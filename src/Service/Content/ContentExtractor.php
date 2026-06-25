<?php

namespace OERManager\Service\Content;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extrae texto de los metadatos y los medios adjuntos de un item para alimentar
 * al clasificador IA (ADR-0007). Clase pura: opera sobre rutas locales que le
 * provee un MediaSourceInterface, sin tocar el core de Omeka, lo que permite
 * probar toda su seguridad con TDD real en el host.
 *
 * Superficie de ataque endurecida (spec §6): la descompresión y el parseo son
 * datos no confiables. Límites por defecto conservadores y configurables.
 *
 * - ZIP/SCORM: se leen las entradas EN MEMORIA por índice (nunca se escribe a
 *   una ruta derivada del nombre de la entrada), así que el zip-slip es
 *   estructuralmente imposible; aun así se rechazan rutas con traversal o
 *   absolutas como defensa en profundidad. Topes de nº de entradas, tamaño
 *   descomprimido, ratio de compresión (anti zip-bomb) y sin recursión en zips
 *   anidados (profundidad 1).
 * - PDF: tope de tamaño antes de parsear; el parser corre con los avisos
 *   silenciados y cualquier fallo se captura (PDF malformado → se salta).
 * - Solo extensiones whitelisted; nunca URLs remotas (sin SSRF).
 */
final class ContentExtractor
{
    public const DEFAULTS = [
        // Presupuesto total del texto extraído (≈ tokens * 4; ~6000 tokens).
        'max_total_chars' => 24000,
        'max_zip_entries' => 1000,
        // Tope acumulado de bytes descomprimidos leídos de un zip.
        'max_zip_total_bytes' => 52428800, // 50 MB
        // Tope por entrada/fichero descomprimido.
        'max_entry_bytes' => 10485760, // 10 MB
        // size/comp_size por encima de esto (y con tamaño relevante) = zip-bomb.
        'max_compression_ratio' => 100,
        'max_pdf_bytes' => 20971520, // 20 MB
        'whitelist' => ['txt', 'html', 'htm', 'xml', 'pdf'],
    ];

    /** @var array<string,mixed> */
    private array $limits;

    /** @var array<string,string> nombre => motivo */
    private array $skipped = [];

    /** @var string[] */
    private array $sources = [];

    /**
     * @param array<string,mixed> $limits sobrescribe DEFAULTS
     */
    public function __construct(array $limits = [])
    {
        $this->limits = $limits + self::DEFAULTS;
    }

    /**
     * @param array<int,array{path:string,mediaType?:string,name?:string}> $files
     */
    public function extract(string $metadataText, array $files): ExtractedContent
    {
        $this->skipped = [];
        $this->sources = [];

        $pieces = [];
        $meta = trim($metadataText);
        if ('' !== $meta) {
            $pieces[] = $meta;
        }

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $name = (string) ($file['name'] ?? ('' !== $path ? basename($path) : 'sin-nombre'));
            $text = $this->extractFile($path, $name);
            if (null !== $text && '' !== trim($text)) {
                $pieces[] = $text;
                $this->sources[] = $name;
            }
        }

        $full = $this->sanitizeUtf8(implode("\n\n", $pieces));
        [$full, $truncated] = $this->truncate($full);

        return new ExtractedContent($full, $truncated, $this->sources, $this->skipped);
    }

    private function extractFile(string $path, string $name): ?string
    {
        if ('' === $path || !is_file($path) || !is_readable($path)) {
            $this->skip($name, 'unreadable');
            return null;
        }
        $ext = $this->ext($name);
        if ('' === $ext) {
            $ext = $this->ext($path);
        }
        return match ($ext) {
            'pdf' => $this->extractPdfFile($path, $name),
            'zip' => $this->extractZip($path, $name),
            'txt', 'html', 'htm', 'xml' => $this->readTextFile($path, $ext),
            default => $this->skipReturn($name, 'unsupported:' . $ext),
        };
    }

    private function extractPdfFile(string $path, string $name): ?string
    {
        $size = filesize($path);
        if (false === $size || $size > (int) $this->limits['max_pdf_bytes']) {
            $this->skip($name, 'pdf_too_large');
            return null;
        }
        $bytes = file_get_contents($path);
        if (false === $bytes) {
            $this->skip($name, 'unreadable');
            return null;
        }
        return $this->parsePdf($bytes, $name);
    }

    /**
     * Parsea bytes de PDF con los avisos del parser silenciados y los fallos
     * capturados: nunca debe abortar la extracción ni filtrar avisos.
     */
    private function parsePdf(string $bytes, string $name): ?string
    {
        $text = null;
        set_error_handler(static fn (): bool => true);
        try {
            $parser = new PdfParser();
            $document = $parser->parseContent($bytes);
            $text = (string) $document->getText();
        } catch (\Throwable $e) {
            $text = null;
        } finally {
            restore_error_handler();
        }
        if (null === $text) {
            $this->skip($name, 'pdf_unreadable');
            return null;
        }
        $text = trim($this->normalizeWhitespace($text));
        if ('' === $text) {
            $this->skip($name, 'pdf_empty');
            return null;
        }
        return $text;
    }

    private function readTextFile(string $path, string $ext): ?string
    {
        $bytes = file_get_contents($path, false, null, 0, (int) $this->limits['max_entry_bytes']);
        if (false === $bytes) {
            return null;
        }
        return $this->normalizeText($bytes, $ext);
    }

    /**
     * Lee un ZIP/SCORM por índice en memoria (sin extraer a disco) con todos los
     * límites de seguridad. Devuelve el texto concatenado de sus entradas
     * whitelisted o null si ninguna aportó texto.
     */
    private function extractZip(string $path, string $name): ?string
    {
        $za = new \ZipArchive();
        if (true !== $za->open($path)) {
            $this->skip($name, 'zip_unreadable');
            return null;
        }

        $pieces = [];
        $entries = 0;
        $totalBytes = 0;
        $maxEntries = (int) $this->limits['max_zip_entries'];
        $maxEntryBytes = (int) $this->limits['max_entry_bytes'];
        $maxTotal = (int) $this->limits['max_zip_total_bytes'];
        $maxRatio = (int) $this->limits['max_compression_ratio'];
        $whitelist = (array) $this->limits['whitelist'];

        for ($i = 0; $i < $za->numFiles; $i++) {
            $stat = $za->statIndex($i);
            if (false === $stat) {
                continue;
            }
            $entryName = (string) $stat['name'];

            if ($this->isUnsafePath($entryName)) {
                $this->skip($entryName, 'unsafe_path');
                continue;
            }
            if (str_ends_with($entryName, '/')) {
                continue; // directorio
            }
            if (++$entries > $maxEntries) {
                $this->skip($entryName, 'too_many_entries');
                break;
            }

            $size = (int) ($stat['size'] ?? 0);
            $comp = (int) ($stat['comp_size'] ?? 0);
            if ($size > $maxEntryBytes) {
                $this->skip($entryName, 'entry_too_large');
                continue;
            }
            if ($comp > 0 && $size > 1024 && ($size / $comp) > $maxRatio) {
                $this->skip($entryName, 'zip_bomb');
                continue;
            }

            $ext = $this->ext($entryName);
            if ('zip' === $ext) {
                $this->skip($entryName, 'nested_zip'); // sin recursión (profundidad 1)
                continue;
            }
            if (!in_array($ext, $whitelist, true)) {
                $this->skip($entryName, 'unsupported:' . $ext);
                continue;
            }

            $totalBytes += $size;
            if ($totalBytes > $maxTotal) {
                $this->skip($entryName, 'zip_total_exceeded');
                break;
            }

            $bytes = $za->getFromIndex($i, $maxEntryBytes);
            if (false === $bytes) {
                $this->skip($entryName, 'entry_unreadable');
                continue;
            }
            $text = 'pdf' === $ext ? $this->parsePdf($bytes, $entryName) : $this->normalizeText($bytes, $ext);
            if (null !== $text && '' !== trim($text)) {
                $pieces[] = $text;
            }
        }
        $za->close();

        return $pieces ? implode("\n\n", $pieces) : null;
    }

    /**
     * ¿La ruta de la entrada es insegura? Absoluta (unix/windows), con unidad o
     * con traversal (`..`) en cualquier separador. Defensa en profundidad: aunque
     * leemos en memoria, no procesamos entradas con nombres maliciosos.
     */
    private function isUnsafePath(string $name): bool
    {
        if ('' === $name) {
            return true;
        }
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return true;
        }
        if (1 === preg_match('#^[A-Za-z]:#', $name)) {
            return true;
        }
        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ('..' === $segment) {
                return true;
            }
        }
        return false;
    }

    private function normalizeText(string $raw, string $ext): string
    {
        if (in_array($ext, ['html', 'htm', 'xml'], true)) {
            // Eliminar script/style enteros antes de quitar etiquetas.
            $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
            $raw = strip_tags($raw);
            $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim($this->normalizeWhitespace($raw));
    }

    private function normalizeWhitespace(string $text): string
    {
        return preg_replace('/\s+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    /**
     * @return array{0:string,1:bool} [texto, ¿truncado?]
     */
    private function truncate(string $text): array
    {
        $max = (int) $this->limits['max_total_chars'];
        if ($max > 0 && mb_strlen($text) > $max) {
            return [mb_substr($text, 0, $max), true];
        }
        return [$text, false];
    }

    private function sanitizeUtf8(string $text): string
    {
        // Una lectura capada puede partir un carácter multibyte: re-codificar
        // descarta secuencias UTF-8 inválidas sin emitir avisos.
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    private function ext(string $name): string
    {
        $pos = strrpos($name, '.');
        return false === $pos ? '' : strtolower(substr($name, $pos + 1));
    }

    private function skip(string $name, string $reason): void
    {
        $this->skipped[$name] = $reason;
    }

    private function skipReturn(string $name, string $reason): ?string
    {
        $this->skip($name, $reason);
        return null;
    }
}
