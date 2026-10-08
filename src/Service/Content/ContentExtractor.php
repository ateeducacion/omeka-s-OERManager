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
        // Cada PDF se lee en un subproceso PHP con su propio tope de memoria y
        // de tiempo (TASK-060): pdfparser topa la memoria por flujo, no por
        // documento, y un PDF de 4 MB lleno de imágenes agotó 512 MB con un
        // fatal que mataba la propuesta. Solo en CLI (Jobs, arneses): en FPM
        // `PHP_BINARY` no sirve para lanzar scripts y se parsea en proceso.
        'pdf_isolation' => true,
        'pdf_worker_memory' => '256M',
        'pdf_worker_timeout' => 60, // segundos
        // ¿Soporta la plataforma `iconv(..., 'UTF-8//TRANSLIT//IGNORE', ...)`?
        // null = detectar en runtime. En musl (Alpine) NO existe y smalot pierde
        // el texto de WinAnsiEncoding —la codificación más común en PDF—, así
        // que el PDF vuelve vacío sin estarlo (TASK-024b). Inyectable para poder
        // probar en host las dos ramas: el host tiene glibc y nunca vería la rota.
        'iconv_translit_supported' => null,
        // ¿Trae la plataforma la extensión `zip` de PHP? null = detectar en
        // runtime. La imagen Debian/glibc que arregla el PDF (TASK-024b) NO la
        // trae, y sin ella `ZipArchive` no existe: hay que saltar el medio con
        // un motivo propio en vez de reventar (TASK-031). Inyectable por el
        // mismo motivo que `iconv_translit_supported`: el host la tiene.
        'zip_supported' => null,
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
        // Ruido propio de un .elpx sin content.xml legible (TASK-053): tema,
        // plantillas de iDevice e iconos. Solo se añade a los .elpx: en un ZIP
        // cualquiera `css/` o `img/` pueden ser del recurso.
        'elpx_noise_path_segments' => ['theme', 'idevices', 'css', 'img', 'custom'],
    ];

    /** Espacio de nombres de `content.xml` de eXeLearning (ODE 2.0). */
    private const ODE_NS = 'http://www.intef.es/xsd/ode';

    /** Clave XOR de los juegos ofuscados de eXeLearning (`escape()` + XOR). */
    private const EXE_GAME_XOR_KEY = 146;

    /** Propiedades del paquete que se pasan como contexto, con su etiqueta. */
    private const ODE_PROPERTIES = [
        'pp_title' => 'Título',
        'pp_subtitle' => 'Subtítulo',
        'pp_description' => 'Descripción',
        'pp_author' => 'Autoría',
        'pp_lang' => 'Idioma',
        'pp_license' => 'Licencia',
        'pp_keywords' => 'Palabras clave',
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
            'zip', 'elpx' => $this->extractZip($path, $name),
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
     * Texto de un PDF, o null si se salta (el motivo queda registrado). Se lee
     * en un subproceso cuando se puede (TASK-060); si no, en proceso.
     */
    private function parsePdf(string $bytes, string $name): ?string
    {
        $text = $this->canIsolatePdf() ? $this->parsePdfInWorker($bytes) : $this->parsePdfInProcess($bytes);
        if (false === $text) {
            // El subproceso murió por memoria o tiempo: ese PDF no, el resto sí.
            $this->skip($name, 'pdf_too_complex');
            return null;
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
     * Parsea bytes de PDF con los avisos del parser silenciados y los fallos
     * capturados: nunca debe abortar la extracción ni filtrar avisos. Sin
     * aislamiento, un agotamiento de memoria sigue siendo un fatal (TASK-060).
     */
    private function parsePdfInProcess(string $bytes): ?string
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
        return $text;
    }

    private function canIsolatePdf(): bool
    {
        return (bool) $this->limits['pdf_isolation']
            && 'cli' === PHP_SAPI
            && '' !== PHP_BINARY
            && function_exists('proc_open')
            && is_file(self::pdfWorker());
    }

    private static function pdfWorker(): string
    {
        return dirname(__DIR__, 3) . '/data/scripts/pdf-text.php';
    }

    /**
     * Lee el PDF en un subproceso (data/scripts/pdf-text.php) con su propio
     * `memory_limit` y un tope de tiempo. Los argumentos van como lista a
     * `proc_open`, nunca interpolados en una orden de shell; los bytes van a un
     * fichero temporal propio que se borra siempre.
     *
     * @return string|null|false texto; null si el PDF no se puede leer; false si
     *     el subproceso murió por memoria o tiempo
     */
    private function parsePdfInWorker(string $bytes): string|null|false
    {
        $tmp = tempnam(sys_get_temp_dir(), 'oer-pdf-');
        if (false === $tmp) {
            return $this->parsePdfInProcess($bytes);
        }
        try {
            file_put_contents($tmp, $bytes);
            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d', 'memory_limit=' . (string) $this->limits['pdf_worker_memory'],
                    '-d', 'display_errors=0',
                    self::pdfWorker(),
                    $tmp,
                    (string) (int) $this->limits['max_entry_bytes'],
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                return $this->parsePdfInProcess($bytes);
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $out = '';
            $code = -1;
            $deadline = microtime(true) + (int) $this->limits['pdf_worker_timeout'];
            while (true) {
                $out .= (string) stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]); // vaciar stderr: un búfer lleno bloquearía al hijo
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $out .= (string) stream_get_contents($pipes[1]);
                    $code = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    break;
                }
                usleep(10000);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            @unlink($tmp);
        }

        return match ($code) {
            0 => $out,
            2 => null,
            default => false,
        };
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

    /**
     * Sonda de la plataforma, evaluada una sola vez. Sin la extensión `zip` la
     * clase no existe y instanciarla lanza un `Error` que, sin esta guarda,
     * tumbaba el propose entero en vez de perder solo ese medio (TASK-031).
     */
    private function supportsZipArchive(): bool
    {
        if (null === $this->limits['zip_supported']) {
            $this->limits['zip_supported'] = class_exists(\ZipArchive::class);
        }
        return (bool) $this->limits['zip_supported'];
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
     * Un paquete eXeLearning (`.elpx`, o un `.zip`/SCORM/IMS exportado con él)
     * se reconoce por su CONTENIDO —un `content.xml` raíz en el espacio ODE—, no
     * por la extensión (TASK-053, decisión del propietario): entonces se lee la
     * estructura de `content.xml` en vez del HTML renderizado, que repetiría el
     * mismo texto, y del resto del ZIP solo los recursos de `content/resources/`.
     *
     * @return string[]
     */
    private function extractZip(string $path, string $name): array
    {
        if (!$this->supportsZipArchive()) {
            $this->skip($name, 'zip_unsupported');
            return [];
        }

        $za = new \ZipArchive();
        if (true !== $za->open($path)) {
            $this->skip($name, 'zip_unreadable');
            return [];
        }

        $budget = ['entries' => 0, 'bytes' => 0];
        $isElpx = 'elpx' === $this->ext($name);
        $ode = $this->readOdeContent($za, $name, $isElpx, $budget);
        if (null !== $ode) {
            $pieces = array_merge(
                $this->odePieces($ode),
                $this->zipEntryPieces($za, $budget, [], 'content/resources/')
            );
        } else {
            $noise = (array) $this->limits['noise_path_segments'];
            if ($isElpx) {
                $noise = array_merge($noise, (array) $this->limits['elpx_noise_path_segments']);
            }
            $pieces = $this->zipEntryPieces($za, $budget, $noise, null);
        }
        $za->close();

        return $pieces;
    }

    /**
     * Recorre las entradas del ZIP aplicando los topes. Con `$onlyPrefix` solo
     * se consideran las entradas bajo ese prefijo; el resto se ignora sin
     * consumir cuota (en un paquete eXeLearning ya se leyó `content.xml`).
     *
     * @param array{entries:int,bytes:int} $budget acumulado entre lecturas del mismo ZIP
     * @param string[] $noise segmentos de directorio de ruido
     * @return string[]
     */
    private function zipEntryPieces(\ZipArchive $za, array &$budget, array $noise, ?string $onlyPrefix): array
    {
        $pieces = [];
        $maxEntries = (int) $this->limits['max_zip_entries'];
        $maxEntryBytes = (int) $this->limits['max_entry_bytes'];
        $maxTotal = (int) $this->limits['max_zip_total_bytes'];
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
            if (null !== $onlyPrefix && !str_starts_with($entryName, $onlyPrefix)) {
                continue;
            }
            // Ruido vendor ANTES de consumir cuota: en paquetes reales (#37129)
            // ~900 entradas de editor quemaban max_zip_entries y el contenido
            // real del final del ZIP ni se llegaba a leer (TASK-022).
            if ($this->isNoisePath($entryName, $noise)) {
                $this->skip($entryName, 'noise_path');
                continue;
            }
            $ext = $this->ext($entryName);
            // En los recursos de un paquete eXeLearning lo que no es texto
            // (imágenes, audio, vídeo: ~340 en el Manual real) no consume cuota.
            if (null !== $onlyPrefix && !in_array($ext, $whitelist, true)) {
                $this->skip($entryName, 'unsupported:' . $ext);
                continue;
            }
            if (++$budget['entries'] > $maxEntries) {
                $this->skip($entryName, 'too_many_entries');
                break;
            }

            $rejection = $this->entryRejection($stat);
            if (null !== $rejection) {
                $this->skip($entryName, $rejection);
                continue;
            }

            if (in_array($ext, ['zip', 'elpx'], true)) {
                $this->skip($entryName, 'nested_zip'); // sin recursión (profundidad 1)
                continue;
            }
            if (!in_array($ext, $whitelist, true)) {
                $this->skip($entryName, 'unsupported:' . $ext);
                continue;
            }

            $budget['bytes'] += (int) ($stat['size'] ?? 0);
            if ($budget['bytes'] > $maxTotal) {
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

        return $pieces;
    }

    /**
     * Motivo por el que una entrada no se descomprime (tamaño o ratio de
     * compresión anti zip-bomb), o null si pasa.
     *
     * @param array<string,mixed> $stat
     */
    private function entryRejection(array $stat): ?string
    {
        $size = (int) ($stat['size'] ?? 0);
        $comp = (int) ($stat['comp_size'] ?? 0);
        if ($size > (int) $this->limits['max_entry_bytes']) {
            return 'entry_too_large';
        }
        if ($comp > 0 && $size > 1024 && ($size / $comp) > (int) $this->limits['max_compression_ratio']) {
            return 'zip_bomb';
        }
        return null;
    }

    /**
     * Lee y valida el `content.xml` raíz de un paquete eXeLearning. Devuelve su
     * XPath (con el prefijo `o` registrado) o null si el ZIP no es eXeLearning.
     * Un `.elpx` sin `content.xml` válido deja un motivo propio y cae al HTML
     * renderizado; un `.zip` sin él sigue por la vía genérica sin motivo.
     *
     * Dato no confiable: se lee en memoria bajo los mismos topes que cualquier
     * entrada y se parsea sin red, sin cargar el DTD y sin sustituir entidades
     * (sin LIBXML_NOENT/LIBXML_DTDLOAD). Un DOCTYPE que declara entidades se
     * rechaza: el formato real no las usa y así no hay XXE ni expansión.
     *
     * @param array{entries:int,bytes:int} $budget
     */
    private function readOdeContent(\ZipArchive $za, string $name, bool $isElpx, array &$budget): ?\DOMXPath
    {
        $index = $za->locateName('content.xml');
        if (false === $index) {
            if ($isElpx) {
                $this->skip($name, 'elpx_content_missing');
            }
            return null;
        }
        $stat = $za->statIndex($index);
        $rejection = false === $stat ? 'entry_unreadable' : $this->entryRejection($stat);
        if (null !== $rejection) {
            $this->skip('content.xml', $rejection);
            if ($isElpx) {
                $this->skip($name, 'elpx_content_invalid');
            }
            return null;
        }
        $bytes = $za->getFromIndex($index, (int) $this->limits['max_entry_bytes']);
        $xpath = false === $bytes ? null : $this->parseOdeXml($bytes);
        if (null === $xpath) {
            if ($isElpx) {
                $this->skip($name, 'elpx_content_invalid');
            }
            return null;
        }
        $budget['entries']++;
        $budget['bytes'] += strlen((string) $bytes);
        return $xpath;
    }

    /**
     * El editor guarda `<ode xmlns="…/ode">`, pero las exportaciones reales
     * (web, SCORM, IMS y el propio .elpx exportado) escriben `<ode>` sin
     * espacio de nombres. Se aceptan las dos; la raíz desnuda solo si trae la
     * estructura ODE, para que un `content.xml` ajeno siga la vía genérica.
     */
    private function parseOdeXml(string $bytes): ?\DOMXPath
    {
        $doc = $this->loadUntrustedXml($bytes);
        $root = $doc?->documentElement;
        if (null === $doc || null === $root || 'ode' !== $root->localName) {
            return null;
        }
        if (null === $root->namespaceURI) {
            $hasOdeStructure = false;
            foreach ($root->childNodes as $child) {
                if (
                    $child instanceof \DOMElement
                    && in_array($child->localName, ['odeProperties', 'odeNavStructures'], true)
                ) {
                    $hasOdeStructure = true;
                    break;
                }
            }
            if (!$hasOdeStructure) {
                return null;
            }
            // Mismo documento con el espacio de nombres declarado, para que
            // todas las consultas usen el prefijo `o`.
            $doc = $this->loadUntrustedXml(
                (string) preg_replace('/<ode(?=[\s>])/', '<ode xmlns="' . self::ODE_NS . '"', $bytes, 1)
            );
            $root = $doc?->documentElement;
        }
        if (null === $doc || null === $root || self::ODE_NS !== $root->namespaceURI) {
            return null;
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('o', self::ODE_NS);
        return $xpath;
    }

    /**
     * Parsea XML no confiable sin red, sin cargar el DTD y sin sustituir
     * entidades. Un DOCTYPE que declara entidades se rechaza entero.
     */
    private function loadUntrustedXml(string $bytes): ?\DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || ($doc->doctype instanceof \DOMDocumentType && $doc->doctype->entities->length > 0)) {
            return null;
        }
        return $doc;
    }

    /**
     * Piezas de un paquete eXeLearning: los metadatos del proyecto y una pieza
     * por página visible, en el orden del árbol. Las páginas, bloques e
     * iDevices con `visibility=false` se saltan (una página oculta arrastra a
     * sus hijas); el contenido `teacherOnly` sí cuenta (decisión 2026-10-05).
     * Los fragmentos repetidos (p. ej. instrucciones copiadas en el juego) se
     * quedan una sola vez.
     *
     * @return string[]
     */
    private function odePieces(\DOMXPath $xp): array
    {
        $pieces = [];
        $meta = $this->odeMetadata($xp);
        if ('' !== $meta) {
            $pieces[] = $meta;
        }

        $pages = [];
        $children = [];
        $position = 0;
        foreach ($xp->query('/o:ode/o:odeNavStructures/o:odeNavStructure') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $id = trim($xp->evaluate('string(o:odePageId)', $node));
            if ('' === $id || isset($pages[$id])) {
                continue;
            }
            $pages[$id] = $node;
            $parent = trim($xp->evaluate('string(o:odeParentPageId)', $node));
            $order = (int) $xp->evaluate('string(o:odeNavStructureOrder)', $node);
            $children[$parent][] = [$order, $position++, $id];
        }
        foreach ($children as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        }
        unset($list);

        // Raíces: sin padre o con un padre que no existe. Recorrido en
        // profundidad con lista de visitados (un ciclo no puede colgarlo).
        $roots = [];
        foreach ($children as $parent => $list) {
            if ('' === $parent || !isset($pages[$parent])) {
                $roots = array_merge($roots, $list);
            }
        }
        usort($roots, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $stack = array_reverse(array_column($roots, 2));
        $visited = [];
        $seen = [];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $page = $pages[$id];
            if ($this->odeHidden($xp, $page, 'o:odeNavStructureProperties/o:odeNavStructureProperty')) {
                continue; // la página oculta no se lee ni se baja a sus hijas
            }
            foreach (array_reverse(array_column($children[$id] ?? [], 2)) as $child) {
                $stack[] = $child;
            }
            $piece = $this->odePageText($xp, $page, $seen);
            if ('' !== $piece) {
                $pieces[] = $piece;
            }
        }
        return $pieces;
    }

    private function odeMetadata(\DOMXPath $xp): string
    {
        $values = [];
        foreach ($xp->query('/o:ode/o:odeProperties/o:odeProperty') ?: [] as $node) {
            $key = strtolower(trim($xp->evaluate('string(o:key)', $node)));
            if (isset(self::ODE_PROPERTIES[$key]) && !isset($values[$key])) {
                $values[$key] = $this->htmlFragmentText($xp->evaluate('string(o:value)', $node));
            }
        }
        // `pp_title` vale «eXeLearning» por defecto: no es un título real.
        if ('exelearning' === strtolower($values['pp_title'] ?? '')) {
            unset($values['pp_title']);
        }
        $lines = [];
        foreach (self::ODE_PROPERTIES as $key => $label) {
            if ('' !== ($values[$key] ?? '')) {
                $lines[] = $label . ': ' . $values[$key];
            }
        }
        return implode("\n", $lines);
    }

    /** @param array<string,bool> $seen fragmentos ya emitidos en el paquete */
    private function odePageText(\DOMXPath $xp, \DOMElement $page, array &$seen): string
    {
        $lines = [];
        $title = $this->normalizeWhitespace(trim($xp->evaluate('string(o:pageName)', $page)));
        if ('' !== $title) {
            $lines[] = 'Página: ' . $title;
        }
        $blocks = $this->odeOrdered($xp, $page, 'o:odePagStructures/o:odePagStructure', 'o:odePagStructureOrder');
        foreach ($blocks as $block) {
            if ($this->odeHidden($xp, $block, 'o:odePagStructureProperties/o:odePagStructureProperty')) {
                continue;
            }
            $blockName = $this->normalizeWhitespace(trim($xp->evaluate('string(o:blockName)', $block)));
            if ('' !== $blockName) {
                $lines[] = $blockName;
            }
            $components = $this->odeOrdered($xp, $block, 'o:odeComponents/o:odeComponent', 'o:odeComponentsOrder');
            foreach ($components as $component) {
                if ($this->odeHidden($xp, $component, 'o:odeComponentsProperties/o:odeComponentsProperty')) {
                    continue;
                }
                foreach ($this->odeComponentTexts($xp, $component) as $text) {
                    $key = mb_strtolower($text);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $lines[] = $text;
                    }
                }
            }
        }
        return implode("\n", $lines);
    }

    /** @return \DOMElement[] hijos de `$query` ordenados por `$orderPath` (estable) */
    private function odeOrdered(\DOMXPath $xp, \DOMElement $parent, string $query, string $orderPath): array
    {
        $items = [];
        foreach ($xp->query($query, $parent) ?: [] as $position => $node) {
            if ($node instanceof \DOMElement) {
                $items[] = [(int) $xp->evaluate('string(' . $orderPath . ')', $node), $position, $node];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($items, 2);
    }

    private function odeHidden(\DOMXPath $xp, \DOMElement $node, string $propertyPath): bool
    {
        foreach ($xp->query($propertyPath, $node) ?: [] as $property) {
            if ('visibility' === trim($xp->evaluate('string(o:key)', $property))) {
                return 'false' === strtolower(trim($xp->evaluate('string(o:value)', $property)));
            }
        }
        return false;
    }

    /**
     * Texto de un iDevice. `htmlView` es la fuente principal; `jsonProperties`
     * suele repetirlo (`textTextarea`), así que solo se usa si el HTML no da
     * nada.
     *
     * @return string[]
     */
    private function odeComponentTexts(\DOMXPath $xp, \DOMElement $component): array
    {
        $texts = [];
        $html = $xp->evaluate('string(o:htmlView)', $component);
        if ('' !== trim($html)) {
            $texts = $this->odeHtmlTexts($html);
        }
        if (!$texts) {
            $json = trim($xp->evaluate('string(o:jsonProperties)', $component));
            if ('' !== $json) {
                $texts = $this->jsonStrings($json);
            }
        }
        return $texts;
    }

    /**
     * Texto de un `htmlView`: el visible, sin scripts, estilos ni elementos
     * `js-hidden`, más los datos de los juegos (div `*-DataGame`) y del vídeo
     * interactivo (`script[type=application/json]`), donde viven preguntas que
     * no están en ningún otro sitio.
     *
     * @return string[]
     */
    private function odeHtmlTexts(string $html): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadHTML(
                '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return [];
        }
        $xp = new \DOMXPath($doc);

        $data = [];
        foreach (iterator_to_array($xp->query('//*[contains(@class, "DataGame")]') ?: []) as $node) {
            $data = array_merge($data, $this->exeGameStrings($node->textContent));
            $node->parentNode?->removeChild($node);
        }
        foreach (iterator_to_array($xp->query('//script[@type="application/json"]') ?: []) as $node) {
            $data = array_merge($data, $this->jsonStrings(trim($node->textContent)));
            $node->parentNode?->removeChild($node);
        }
        $hidden = '//script | //style | //*[contains(concat(" ", normalize-space(@class), " "), " js-hidden ")]';
        foreach (iterator_to_array($xp->query($hidden) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Espacio solo en las fronteras de bloque: las etiquetas en línea
        // (`<strong>`, `<a>`) no deben partir palabras ni separar la puntuación.
        $bodies = $xp->query('//body');
        $body = false === $bodies ? null : $bodies->item(0);
        $markup = null === $body ? '' : (string) $doc->saveHTML($body);
        $markup = preg_replace(
            '#<(/?)(p|div|li|h[1-6]|br|tr|td|th|caption|section|article|ul|ol|table|blockquote|figcaption)\b#i',
            ' <$1$2',
            $markup
        ) ?? $markup;
        $visible = $this->normalizeText($markup, 'html');
        return array_values(array_filter(
            array_merge('' === $visible ? [] : [$visible], $data),
            static fn (string $s): bool => '' !== $s
        ));
    }

    /**
     * Datos de un juego de eXeLearning. Tres codificaciones vistas en paquetes
     * reales: JSON plano, `encodeURIComponent` y `escape()` de unidades XOR
     * 146 (la mayoría de cuestionarios). Lo que no decodifica a JSON se ignora.
     *
     * @return string[]
     */
    private function exeGameStrings(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }
        $candidates = [$raw];
        if (str_contains($raw, '%')) {
            $candidates[] = rawurldecode($raw);
        }
        $candidates[] = $this->exeXorUnescape($raw);
        foreach ($candidates as $candidate) {
            if (str_starts_with(ltrim($candidate), '{') || str_starts_with(ltrim($candidate), '[')) {
                $strings = $this->jsonStrings($candidate);
                if ($strings) {
                    return $strings;
                }
            }
        }
        return [];
    }

    /** Inverso de `escape()` de JavaScript seguido del XOR de eXeLearning. */
    private function exeXorUnescape(string $raw): string
    {
        $decoded = preg_replace_callback(
            '/%u([0-9a-fA-F]{4})|%([0-9a-fA-F]{2})/',
            static fn (array $m): string => (string) mb_chr((int) hexdec('' !== $m[1] ? $m[1] : $m[2]), 'UTF-8'),
            $raw
        ) ?? '';
        $out = '';
        foreach (mb_str_split($decoded, 1, 'UTF-8') as $char) {
            $out .= (string) mb_chr(mb_ord($char, 'UTF-8') ^ self::EXE_GAME_XOR_KEY, 'UTF-8');
        }
        return $out;
    }

    /**
     * Strings de contenido de un JSON, con el mismo filtro de ruido técnico y
     * el mismo tope de nodos que un `.json` suelto, pero sin registrar motivo:
     * un JSON interno que no aporta no es un medio saltado.
     *
     * @return string[]
     */
    private function jsonStrings(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }
        $out = [];
        $nodes = 0;
        $this->collectJsonStrings($data, $out, $nodes, (int) $this->limits['max_json_nodes']);
        return array_values(array_filter(
            array_map(fn (string $s): string => trim($this->normalizeWhitespace($s)), $out),
            static fn (string $s): bool => '' !== $s
        ));
    }

    /** Texto plano de un fragmento HTML (o de texto ya plano). */
    private function htmlFragmentText(string $value): string
    {
        return 1 === preg_match('/<[a-z][^>]*>/i', $value)
            ? $this->normalizeText($value, 'html')
            : trim($this->normalizeWhitespace($value));
    }

    /**
     * ¿La entrada vive bajo un directorio de ruido vendor (editores, plugins,
     * fuentes…)? Solo cuentan los segmentos de DIRECTORIO: un fichero llamado
     * `fonts.html` no es la carpeta `fonts/`.
     *
     * @param string[] $deny
     */
    private function isNoisePath(string $entryName, array $deny): bool
    {
        $segments = explode('/', strtolower(str_replace('\\', '/', $entryName)));
        array_pop($segments); // el nombre de fichero no cuenta
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
