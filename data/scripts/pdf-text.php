<?php

/**
 * Subproceso de ContentExtractor::parsePdf() (TASK-060).
 *
 * smalot/pdfparser topa la memoria por flujo comprimido, no por documento: un
 * PDF de 4 MB con muchas imágenes agotó los 512 MB de PHP (2026-10-08), un error
 * fatal que no se puede capturar y que mataba la propuesta entera. Aquí el PDF
 * se lee en un proceso aparte, con el `memory_limit` que fija el padre; si se
 * agota, muere este proceso y el padre salta ese PDF.
 *
 * Uso (lo lanza el padre, nunca un usuario):
 *   php -d memory_limit=256M pdf-text.php <ruta-del-pdf> <tope-de-decodificación>
 *
 * Salida: el texto por stdout y código 0; código 2 si el PDF no se puede leer.
 * Cualquier otro final (memoria, tiempo) lo interpreta el padre como demasiado
 * complejo.
 */

declare(strict_types=1);

$path = (string) ($argv[1] ?? '');
$decodeLimit = (int) ($argv[2] ?? 0);

// Mismo autoloader que registra Module.php: Omeka no carga el vendor del módulo.
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if ('' === $path || !is_file($path) || !is_file($autoload)) {
    exit(2);
}
require $autoload;

set_error_handler(static fn (): bool => true);
try {
    $config = new \Smalot\PdfParser\Config();
    $config->setRetainImageContent(false);
    $config->setDecodeMemoryLimit($decodeLimit);
    $text = (string) (new \Smalot\PdfParser\Parser([], $config))
        ->parseContent((string) file_get_contents($path))
        ->getText();
} catch (\Throwable $e) {
    exit(2);
}

fwrite(STDOUT, $text);
exit(0);
