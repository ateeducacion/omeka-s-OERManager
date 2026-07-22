<?php

namespace OERManager\Service\Content;

use Smalot\PdfParser\Config as PdfConfig;
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
        // ¿Soporta la plataforma `iconv(..., 'UTF-8//TRANSLIT//IGNORE', ...)`?
        // null = detectar en runtime. En musl (Alpine) NO existe y smalot pierde
        // el texto de WinAnsiEncoding —la codificación más común en PDF—, así
        // que el PDF vuelve vacío sin estarlo (TASK-024b). Inyectable para poder
        // probar en host las dos ramas: el host tiene glibc y nunca vería la rota.
        'iconv_translit_supported' => null,
        // Tope de nodos del recorrido JSON (anti-JSON patológico/profundo).
        'max_json_nodes' => 5000,
        // Longitud mínima para aceptar un string suelto (sin varias palabras).
        'min_text_len' => 25,
        'whitelist' => ['txt', 'html', 'htm', 'xml', 'pdf', 'json'],
        // Directorios vendor/ruido dentro de un ZIP: sus entradas se saltan sin
        // consumir cuota (TASK-022: los paquetes de herramientas de autor
        // arrastran editores completos que entierran el contenido real).
        'noise_path_segments' => [
            'ckeditor', 'tinymce', 'node_modules', 'vendor', 'plugins',
            'samples', 'fonts', 'font', 'lib', 'libs', '.git',
        ],
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
            $added = false;
            foreach ($this->extractFile($path, $name) as $text) {
                if ('' !== trim($text)) {
                    $pieces[] = $text;
                    $added = true;
                }
            }
            if ($added) {
                $this->sources[] = $name;
            }
        }

        $pieces = array_map([$this, 'sanitizeUtf8'], $pieces);
        [$full, $truncated] = $this->assemble($pieces);

        return new ExtractedContent($full, $truncated, $this->sources, $this->skipped);
    }

    /**
     * Extrae las piezas de texto de un fichero. Un ZIP produce una pieza por
     * entrada (granularidad necesaria para el reparto equitativo del presupuesto);
     * el resto, una única pieza.
     *
     * @return string[]
     */
    private function extractFile(string $path, string $name): array
    {
        if ('' === $path || !is_file($path) || !is_readable($path)) {
            $this->skip($name, 'unreadable');
            return [];
        }
        $ext = $this->ext($name);
        if ('' === $ext) {
            $ext = $this->ext($path);
        }
        return match ($ext) {
            'pdf' => $this->asPieces($this->extractPdfFile($path, $name)),
            'zip' => $this->extractZip($path, $name),
            'json' => $this->asPieces($this->extractJsonFile($path, $name)),
            'txt', 'html', 'htm', 'xml' => $this->asPieces($this->readTextFile($path, $ext)),
            default => $this->skipReturn($name, 'unsupported:' . $ext),
        };
    }

    /** @return string[] */
    private function asPieces(?string $text): array
    {
        return null === $text || '' === trim($text) ? [] : [$text];
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
            // Endurecimiento (revisión adversaria, finding #2): topar la memoria de
            // decodificación de streams FlateDecode (anti PDF-bomb, simétrico al
            // guard de ratio del ZIP) y no retener imágenes. El cap de tamaño de
            // entrada no acota el ratio de compresión interno del PDF.
            $config = new PdfConfig();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit((int) $this->limits['max_entry_bytes']);
            $parser = new PdfParser([], $config);
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
            // Distinguir «este PDF no tiene texto» de «esta plataforma no sabe
            // leerlo» (TASK-024b): sin `//TRANSLIT` el parser devuelve vacío
            // aunque el PDF tenga una capa de texto perfecta.
            $this->skip($name, $this->supportsIconvTranslit() ? 'pdf_empty' : 'pdf_iconv_unsupported');
            return null;
        }
        return $text;
    }

    /**
     * Sonda de la plataforma, evaluada una sola vez. En musl devuelve `false`
     * para cualquier conversión con `//TRANSLIT`; se usa ASCII puro para no
     * depender de la codificación de este fichero.
     */
    private function supportsIconvTranslit(): bool
    {
        if (null === $this->limits['iconv_translit_supported']) {
            $this->limits['iconv_translit_supported']
                = false !== @iconv('CP1252', 'UTF-8//TRANSLIT//IGNORE', 'a');
        }
        return (bool) $this->limits['iconv_translit_supported'];
    }

    private function readTextFile(string $path, string $ext): ?string
    {
        $bytes = file_get_contents($path, false, null, 0, (int) $this->limits['max_entry_bytes']);
        if (false === $bytes) {
            return null;
        }
        return $this->normalizeText($bytes, $ext);
    }

    private function extractJsonFile(string $path, string $name): ?string
    {
        $bytes = file_get_contents($path, false, null, 0, (int) $this->limits['max_entry_bytes']);
        if (false === $bytes) {
            $this->skip($name, 'unreadable');
            return null;
        }
        return $this->parseJson($bytes, $name);
    }

    /**
     * Extrae los valores string significativos de un JSON (cualquier herramienta).
     * JSON inválido se salta; el recorrido está acotado por nº de nodos.
     */
    private function parseJson(string $bytes, string $name): ?string
    {
        try {
            $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->skip($name, 'json_invalid');
            return null;
        }
        $out = [];
        $nodes = 0;
        $this->collectJsonStrings($data, $out, $nodes, (int) $this->limits['max_json_nodes']);
        $text = trim($this->normalizeWhitespace(implode("\n", $out)));
        if ('' === $text) {
            $this->skip($name, 'json_empty');
            return null;
        }
        return $text;
    }

    /**
     * Recorre el árbol JSON recogiendo strings, con tope de nodos visitados.
     *
     * @param mixed $node
     * @param string[] $out
     */
    private function collectJsonStrings(mixed $node, array &$out, int &$nodes, int $maxNodes): void
    {
        if ($nodes++ >= $maxNodes) {
            return;
        }
        if (is_array($node)) {
            foreach ($node as $value) {
                $this->collectJsonStrings($value, $out, $nodes, $maxNodes);
            }
            return;
        }
        if (is_string($node)) {
            $text = $this->meaningfulText($node);
            if (null !== $text) {
                $out[] = $text;
            }
        }
    }

    /**
     * ¿El string es texto de contenido (no un id/ruta/url/hash/nombre de fichero)?
     * Devuelve el texto saneado (HTML stripped) o null si es ruido técnico.
     */
    private function meaningfulText(string $raw): ?string
    {
        $value = trim($raw);
        if ('' === $value) {
            return null;
        }
        if (1 === preg_match('/<[a-z][^>]*>/i', $value)) {
            $value = trim($this->normalizeText($value, 'html'));
            if ('' === $value) {
                return null;
            }
        }
        // Descartar tokens técnicos.
        if (
            1 === preg_match('#^https?://#i', $value)
            || str_contains($value, '/')
            || 1 === preg_match('/^[0-9a-f]{8,}$/i', $value)
        ) {
            return null;
        }
        $fileExt = '/^[\w.-]+\.(png|jpe?g|gif|svg|css|js|json|woff2?|ttf|eot|'
            . 'mp[34]|html?|xml|xsd|dtd)$/i';
        if (1 === preg_match($fileExt, $value)) {
            return null;
        }
        // Identificadores técnicos de una sola palabra (ids de interfaz de
        // herramientas de autor, TASK-022): token sin espacios con dígitos,
        // guion (bajo o medio) o transición camelCase → ruido, por largo que sea
        // (`imagelink_<hash>`, `interface_view_581-001`, `navigationSectionInteracted`,
        // `ntx-text-font-style-normal`). Las palabras naturales no los contienen
        // y las compuestas cortas ya caían por min_text_len.
        if (
            0 === preg_match('/\s/u', $value)
            && (1 === preg_match('/[\d_-]/', $value) || 1 === preg_match('/\p{Ll}\p{Lu}/u', $value))
        ) {
            return null;
        }
        // Reglas CSS embebidas como string (multi-palabra, se colaban):
        // selector + bloque `{...}` o `!important` (TASK-022, caso #37129).
        if (
            str_contains($value, '!important')
            || 1 === preg_match('/^[.#@][^{]*\{.*\}/su', $value)
        ) {
            return null;
        }
        $words = preg_split('/\s+/', $value) ?: [];
        $multiWord = count(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 1)) >= 2;
        if (!$multiWord && mb_strlen($value) < (int) $this->limits['min_text_len']) {
            return null;
        }
        return $value;
    }

    /**
     * Lee un ZIP/SCORM por índice en memoria (sin extraer a disco) con todos los
     * límites de seguridad. Devuelve una pieza de texto por entrada whitelisted
     * que aportó contenido (el reparto de presupuesto es por pieza).
     *
     * @return string[]
     */
    private function extractZip(string $path, string $name): array
    {
        $za = new \ZipArchive();
        if (true !== $za->open($path)) {
            $this->skip($name, 'zip_unreadable');
            return [];
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
            // Ruido vendor ANTES de consumir cuota: en paquetes reales (#37129)
            // ~900 entradas de editor quemaban max_zip_entries y el contenido
            // real del final del ZIP ni se llegaba a leer (TASK-022).
            if ($this->isNoisePath($entryName)) {
                $this->skip($entryName, 'noise_path');
                continue;
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
            $text = match ($ext) {
                'pdf' => $this->parsePdf($bytes, $entryName),
                'json' => $this->parseJson($bytes, $entryName),
                default => $this->normalizeText($bytes, $ext),
            };
            if (null !== $text && '' !== trim($text)) {
                $pieces[] = $text;
            }
        }
        $za->close();

        return $pieces;
    }

    /**
     * ¿La entrada vive bajo un directorio de ruido vendor (editores, plugins,
     * fuentes…)? Solo cuentan los segmentos de DIRECTORIO: un fichero llamado
     * `fonts.html` no es la carpeta `fonts/`.
     */
    private function isNoisePath(string $entryName): bool
    {
        $segments = explode('/', strtolower(str_replace('\\', '/', $entryName)));
        array_pop($segments); // el nombre de fichero no cuenta
        $deny = (array) $this->limits['noise_path_segments'];
        foreach ($segments as $segment) {
            if (in_array($segment, $deny, true)) {
                return true;
            }
        }
        return false;
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
     * Ensambla las piezas bajo el presupuesto total con reparto equitativo
     * (water-filling, TASK-022): si caben todas, van completas; si no, las
     * piezas cortas conservan todo su texto y las largas se reparten el resto a
     * partes iguales. Así una pieza enorme/ruidosa no expulsa la señal de las
     * demás (antes el truncado head-first perdía el final del contexto), y una
     * fuente única sigue disponiendo del presupuesto completo.
     *
     * @param string[] $pieces
     * @return array{0:string,1:bool} [texto, ¿truncado?]
     */
    private function assemble(array $pieces): array
    {
        $pieces = array_values(array_filter(
            $pieces,
            static fn (string $p): bool => '' !== trim($p)
        ));
        if (!$pieces) {
            return ['', false];
        }
        $separator = "\n\n";
        $max = (int) $this->limits['max_total_chars'];
        $lengths = array_map('mb_strlen', $pieces);
        $sepTotal = (count($pieces) - 1) * strlen($separator);
        if ($max <= 0 || array_sum($lengths) + $sepTotal <= $max) {
            return [implode($separator, $pieces), false];
        }

        // Asignación de corta a larga: cada pieza toma como mucho su parte
        // proporcional del presupuesto restante; lo que no consume una corta
        // queda disponible para las largas.
        $budget = max(0, $max - $sepTotal);
        $order = array_keys($lengths);
        usort($order, static fn (int $a, int $b): int => $lengths[$a] <=> $lengths[$b]);
        $alloc = [];
        $remaining = count($order);
        foreach ($order as $i) {
            $share = intdiv($budget, $remaining);
            $take = min($lengths[$i], $share);
            $alloc[$i] = $take;
            $budget -= $take;
            $remaining--;
        }

        $out = [];
        foreach ($pieces as $i => $piece) {
            $cut = mb_substr($piece, 0, $alloc[$i]);
            if ('' !== trim($cut)) {
                $out[] = $cut;
            }
        }
        return [implode($separator, $out), true];
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

    /** @return string[] */
    private function skipReturn(string $name, string $reason): array
    {
        $this->skip($name, $reason);
        return [];
    }
}
