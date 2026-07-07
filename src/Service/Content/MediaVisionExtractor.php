<?php

declare(strict_types=1);

namespace OERManager\Service\Content;

use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\TraceableInterface;
use OERManager\Service\Llm\LlmClientInterface;

/**
 * Extractor de visión (ADR-0011): rescata la señal de las imágenes y de los PDF
 * escaneados (sin capa de texto) que el ContentExtractor no puede leer, enviando el
 * binario DIRECTO al LLM de extracción (sin tooling local) como bloques image/
 * document y devolviendo su descripción textual para el ItemContext.
 *
 * Gobernado por dos puertas (spec §6, privacidad): el master toggle `vision_enabled`
 * (egress de binarios a un tercero, off por defecto) y la capacidad del proveedor
 * (supportsImages()/supportsPdf()). El filtro de imágenes es PURO (heurístico por
 * nombre y tamaño + top-N), testeable en host; la llamada usa el cliente LLM
 * inyectado (fake en tests). Solo rutas locales del store (sin SSRF). El texto
 * visible viaja como dato no-instrucción (PromptBuilder::buildVisionPrompt()).
 *
 * Implementa TraceableInterface para auditar la visión (o su omisión) en el panel.
 */
final class MediaVisionExtractor implements TraceableInterface
{
    /**
     * Nombres de ruido que NO son señal de contenido (logos, iconos, fondos,
     * miniaturas…). Lookaround por letras para no morder palabras de contenido
     * (p. ej. «iconografia» no es un icono).
     */
    private const NOISE = '/(?<![a-z])(placeholder|sprite|icon|logo|favicon|banner|bg|background'
        . '|thumb|thumbnail|avatar|button|btn|spacer|divider|bullet)(?![a-z])/i';

    /** Tamaño mínimo para considerar una imagen señal (descarta iconos diminutos). */
    public const DEFAULT_MIN_IMAGE_BYTES = 8192; // 8 KB
    /** Tope por imagen enviada al proveedor (Anthropic acota ~5 MB por imagen). */
    public const DEFAULT_MAX_IMAGE_BYTES = 5242880; // 5 MB
    /** Tope del PDF escaneado enviado como documento nativo. */
    private const MAX_PDF_BYTES = 20971520; // 20 MB

    /** @var array<int,array<string,mixed>> */
    private array $trace = [];

    public function __construct(
        private LlmClientInterface $llm,
        private PromptBuilder $prompts,
        private bool $enabled = false,
        private int $maxImages = 3,
        private int $minImageBytes = self::DEFAULT_MIN_IMAGE_BYTES,
        private int $maxImageBytes = self::DEFAULT_MAX_IMAGE_BYTES,
        private int $maxTokens = 1024,
        private ?float $temperature = null
    ) {
    }

    /**
     * Filtro heurístico PURO: descarta ruido por nombre y por tamaño, ordena por
     * tamaño descendente y toma las top-N. No lee ficheros ni llama al LLM.
     *
     * @param array<int,array{path?:string,mediaType?:string,name?:string,size?:int}> $candidates
     * @return array<int,array{path?:string,mediaType?:string,name?:string,size?:int}>
     */
    public function selectImages(array $candidates): array
    {
        $kept = [];
        foreach ($candidates as $candidate) {
            $name = (string) ($candidate['name'] ?? '');
            $size = (int) ($candidate['size'] ?? 0);
            if (1 === preg_match(self::NOISE, $name)) {
                continue;
            }
            if ($size < $this->minImageBytes || $size > $this->maxImageBytes) {
                continue;
            }
            $kept[] = $candidate;
        }
        usort($kept, static fn (array $a, array $b): int => (int) ($b['size'] ?? 0) <=> (int) ($a['size'] ?? 0));
        return array_slice($kept, 0, max(0, $this->maxImages));
    }

    /**
     * Describe las imágenes seleccionadas (top-N) y los PDF escaneados con el LLM de
     * extracción. Devuelve las descripciones (vacío si la visión está apagada, el
     * proveedor no la soporta o no hay binarios que aporten señal). Una sola llamada.
     *
     * @param array<int,array{path?:string,mediaType?:string,name?:string,size?:int}> $images
     * @param array<int,array{path?:string,mediaType?:string,name?:string}> $pdfs
     * @return string[]
     */
    public function describe(array $images, array $pdfs): array
    {
        $this->trace = [];
        if (!$this->enabled) {
            $this->trace[] = ['step' => 'vision', 'skipped' => 'disabled'];
            return [];
        }

        $blocks = [];
        $imageCount = 0;
        if ($this->llm->supportsImages()) {
            foreach ($this->selectImages($images) as $image) {
                $block = $this->binaryBlock(
                    'image',
                    (string) ($image['path'] ?? ''),
                    (string) ($image['mediaType'] ?? ''),
                    $this->maxImageBytes
                );
                if (null !== $block) {
                    $blocks[] = $block;
                    $imageCount++;
                }
            }
        }
        $pdfCount = 0;
        if ($this->llm->supportsPdf()) {
            foreach ($pdfs as $pdf) {
                $block = $this->binaryBlock(
                    'document',
                    (string) ($pdf['path'] ?? ''),
                    'application/pdf',
                    self::MAX_PDF_BYTES
                );
                if (null !== $block) {
                    $blocks[] = $block;
                    $pdfCount++;
                }
            }
        }

        if ([] === $blocks) {
            $hadCandidates = [] !== $images || [] !== $pdfs;
            $this->trace[] = [
                'step' => 'vision',
                'skipped' => $hadCandidates ? 'provider_no_vision' : 'no_candidates',
            ];
            return [];
        }

        $prompt = $this->prompts->buildVisionPrompt();
        $content = array_merge([['type' => 'text', 'text' => $prompt['user']]], $blocks);
        // Perfil de inferencia compartido: temperatura solo si está configurada
        // (los Opus 4.6+ la rechazan); se traza para comparar entre proveedores.
        $options = ['system' => $prompt['system'], 'max_tokens' => $this->maxTokens];
        if (null !== $this->temperature) {
            $options['temperature'] = $this->temperature;
        }
        $response = $this->llm->chat([['role' => 'user', 'content' => $content]], $options);
        $description = trim($response->text());
        $this->trace[] = [
            'step' => 'vision',
            'images' => $imageCount,
            'pdfs' => $pdfCount,
            'system' => $prompt['system'],
            'llm_options' => array_diff_key($options, ['system' => '']),
            'description' => $description,
        ];
        return '' === $description ? [] : [$description];
    }

    /**
     * Lee el binario local y construye el bloque neutral (base64), o null si la ruta
     * no es legible o el tamaño está fuera de rango. Solo store local (sin SSRF).
     *
     * @return array{type:string,media_type:string,data:string}|null
     */
    private function binaryBlock(string $type, string $path, string $mediaType, int $maxBytes): ?array
    {
        if ('' === $path || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        if (false === $size || 0 === $size || $size > $maxBytes) {
            return null;
        }
        $bytes = file_get_contents($path);
        if (false === $bytes || '' === $bytes) {
            return null;
        }
        return [
            'type' => $type,
            'media_type' => '' !== $mediaType ? $mediaType : 'application/octet-stream',
            'data' => base64_encode($bytes),
        ];
    }

    public function getTrace(): array
    {
        return $this->trace;
    }

    public function clearTrace(): void
    {
        $this->trace = [];
    }
}
