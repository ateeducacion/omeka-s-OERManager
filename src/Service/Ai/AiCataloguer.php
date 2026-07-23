<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\ExtractedContent;
use OERManager\Service\Content\ItemContext;
use OERManager\Service\Content\MediaVisionExtractor;

/**
 * Orquestador de la catalogación IA-assistida (ADR-0007): extrae el contenido
 * textual seguro del item (medios), lo destila en una ficha fiel (ADR-0011) con
 * el modelo de extracción barato, y compone un ItemContext estructurado que pasa
 * a los dos clasificadores (curricular jerárquico + ejes), fusionando sus
 * propuestas en un único mapa que pre-rellena el panel de re-catalogación de 4a.
 * La IA propone; el curador confirma: aquí no se escribe nada en el catálogo.
 */
final class AiCataloguer
{
    public function __construct(
        private ContentExtractor $extractor,
        private MediaVisionExtractor $vision,
        private ContextDistiller $distiller,
        private ClassifierInterface $curricular,
        private ClassifierInterface $tags,
        // Tope de confirmación de PDF grandes (TASK-025): misma fuente de verdad
        // que el tope de envío de MediaVisionExtractor (§3b del spec). PDF entre
        // el tope de parseo (20 MB) y este → confirmable por el curador; por
        // encima → fuera de alcance (no se ofrece).
        private int $visionMaxPdfBytes = MediaVisionExtractor::DEFAULT_MAX_PDF_BYTES
    ) {
    }

    /**
     * @param array<int,array{path:string,mediaType?:string,name?:string,size?:int}> $files
     * @param array<int,array{path:string,mediaType?:string,name?:string,size?:int}> $images
     * @param string $largePdfDecision ask (default) | include | skip — TASK-025
     * @return array<string,mixed>
     */
    public function propose(
        string $metadataText,
        array $files,
        array $images = [],
        string $largePdfDecision = 'ask'
    ): array {
        if ($this->curricular instanceof TraceableInterface) {
            $this->curricular->clearTrace();
        }
        if ($this->tags instanceof TraceableInterface) {
            $this->tags->clearTrace();
        }
        $this->distiller->clearTrace();
        $this->vision->clearTrace();

        // Extrae SOLO el texto de los medios (sin prefijar metadatos): el contexto
        // los mantiene separados con su procedencia (ADR-0011), y el truncado por
        // presupuesto protege el contenido del medio.
        $media = $this->extractor->extract('', $files);

        // PDF grandes (TASK-025): los que superan el tope de PARSEO (pdf_too_large)
        // pueden rescatarse por visión, que no parsea sino que manda el binario. Se
        // clasifican en confirmables (<= tope de visión) y fuera de alcance. Si hay
        // confirmables, la visión puede rescatarlos y el curador no ha decidido, se
        // CORTA aquí —antes de gastar un solo token— y se pide confirmación.
        $oversize = $this->classifyOversizePdfs($files, $media->skipped());
        // Cualquier decisión que no sea explícitamente include/skip = «aún no
        // decidido» (ask): un booleano no distinguiría «no preguntado» de «dijo
        // que no», y un valor basura del POST no debe saltarse la confirmación.
        $undecided = !in_array($largePdfDecision, ['include', 'skip'], true);
        if (
            $undecided
            && [] !== $oversize['confirmable']
            && $this->vision->canRescuePdf()
        ) {
            return [
                'needs_confirmation' => $oversize,
                'alignment' => [],
                'justifications' => [],
                'content' => $this->contentBlock($media, false, $oversize['too_large']),
                'debug' => [],
            ];
        }

        // Visión (ADR-0011): rescata las imágenes (top-N) y los PDF escaneados que el
        // ContentExtractor no pudo leer. Gobernada por el toggle/proveedor dentro del
        // extractor; off-by-default => no-op sin red. El PDF sin capa de texto se
        // detecta por su motivo de salto.
        $rescuable = $this->rescuablePdfs($files, $media->skipped(), $largePdfDecision);
        $visionDescriptions = $this->vision->describe($images, $rescuable);
        $context = new ItemContext($metadataText, $media->text(), '', $visionDescriptions);

        // Destilación (ADR-0011): el modelo barato produce una ficha fiel que usan
        // los pasos gruesos; los pasos finos conservan el crudo de medios.
        $ficha = $this->distiller->distill($context);
        if ('' !== $ficha) {
            $context = $context->withFicha($ficha);
        }

        $alignment = [];
        // Sin señal no hay nada que clasificar: no se gasta ni un token.
        if (!$context->isEmpty()) {
            $alignment = $this->curricular->classify($context) + $this->tags->classify($context);
        }

        return [
            'alignment' => $alignment,
            // Justificación por saber/criterio (TASK-023): el clasificador
            // curricular la expone si la soporta; los ejes no la llevan.
            'justifications' => method_exists($this->curricular, 'getJustifications')
                ? $this->curricular->getJustifications()
                : [],
            'content' => $this->contentBlock($media, $context->isEmpty(), $oversize['too_large']),
            'debug' => [
                'content_text' => $context->fineText(),
                'ficha' => $ficha,
                'vision' => $this->vision->getTrace(),
                'distillation' => $this->distiller->getTrace(),
                'curricular' => $this->curricular instanceof TraceableInterface
                    ? $this->curricular->getTrace() : [],
                'tags' => $this->tags instanceof TraceableInterface
                    ? $this->tags->getTrace() : [],
            ],
        ];
    }

    /**
     * PDF cuyo contenido no pudo leerse como texto (escaneado, sin capa de texto,
     * o perdido por una plataforma sin `iconv //TRANSLIT` — TASK-024b):
     * candidatos a rescate por visión. Se identifican por su motivo de salto y se
     * cruzan con los ficheros locales para recuperar la ruta del binario; las
     * entradas internas de un ZIP no tienen ruta y se omiten.
     *
     * `pdf_too_large` (TASK-025) solo se rescata cuando el curador lo ha confirmado
     * (decisión `include`): es un fichero grande cuyo envío tiene coste, a
     * diferencia de los demás motivos, que son ficheros pequeños ya bajo el tope de
     * parseo y se rescatan siempre.
     *
     * @param array<int,array{path?:string,mediaType?:string,name?:string,size?:int}> $files
     * @param array<string,string> $skipped nombre => motivo
     * @return array<int,array{path:string,mediaType:string,name:string}>
     */
    private function rescuablePdfs(array $files, array $skipped, string $largePdfDecision = 'ask'): array
    {
        $reasons = ['pdf_unreadable', 'pdf_empty', 'pdf_iconv_unsupported'];
        if ('include' === $largePdfDecision) {
            $reasons[] = 'pdf_too_large';
        }
        $rescue = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ('' === $path) {
                continue;
            }
            $name = (string) ($file['name'] ?? basename($path));
            if (!in_array($skipped[$name] ?? '', $reasons, true)) {
                continue;
            }
            $rescue[] = [
                'path' => $path,
                'mediaType' => (string) ($file['mediaType'] ?? 'application/pdf'),
                'name' => $name,
            ];
        }
        return $rescue;
    }

    /**
     * Clasifica los PDF marcados `pdf_too_large` (superan el tope de PARSEO) en
     * confirmables por el curador (tamaño <= tope de visión, rescatables enviando
     * el binario) y fuera de alcance (por encima del tope de visión, que el
     * proveedor rechazaría). PURA: usa el `size` de la entrada, no toca disco. Las
     * entradas saltadas sin fichero (internas de ZIP) se omiten. (TASK-025)
     *
     * @param array<int,array{path?:string,name?:string,size?:int}> $files
     * @param array<string,string> $skipped nombre => motivo
     * @return array{confirmable:array<int,array{name:string,size:int}>,too_large:array<int,array{name:string,size:int}>}
     */
    public function classifyOversizePdfs(array $files, array $skipped): array
    {
        $confirmable = [];
        $tooLarge = [];
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? basename((string) ($file['path'] ?? '')));
            if ('pdf_too_large' !== ($skipped[$name] ?? '') || '' === $name) {
                continue;
            }
            $size = (int) ($file['size'] ?? 0);
            $entry = ['name' => $name, 'size' => $size];
            if ($size <= $this->visionMaxPdfBytes) {
                $confirmable[] = $entry;
            } else {
                $tooLarge[] = $entry;
            }
        }
        return ['confirmable' => $confirmable, 'too_large' => $tooLarge];
    }

    /**
     * Bloque `content` común a todos los retornos, con los PDF fuera de alcance
     * expuestos para que el panel los explique (TASK-025).
     *
     * @param array<int,array{name:string,size:int}> $tooLargePdfs
     * @return array<string,mixed>
     */
    private function contentBlock(ExtractedContent $media, bool $empty, array $tooLargePdfs): array
    {
        return [
            'truncated' => $media->isTruncated(),
            'empty' => $empty,
            'sources' => $media->sources(),
            'skipped' => $media->skipped(),
            'too_large_pdfs' => $tooLargePdfs,
        ];
    }
}
