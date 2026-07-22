<?php

/**
 * Diagnóstico puntual de `pdf_unreadable` / `pdf_empty` en contenedor.
 *
 * ContentExtractor::parsePdf() captura el Throwable y lo convierte en un motivo
 * de salto, lo que es correcto en producción pero deja el fallo real invisible.
 * Este script repite el parseo mostrando la excepción concreta.
 *
 * SOLO LECTURA. Uso: php modules/OERManager/test/container/pdf-diagnose.php <itemId>
 */

chdir('/var/www/html');
require 'bootstrap.php';

use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$mediaSource = $services->get(\OERManager\Service\Content\MediaSourceInterface::class);

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "uso: php pdf-diagnose.php <itemId>\n");
    exit(2);
}

$files = $mediaSource->filesFor($id);
printf("item %d: %d fichero(s) devueltos por filesFor()\n", $id, count($files));

foreach ($files as $file) {
    $name = $file['name'] ?? '(sin nombre)';
    $path = $file['path'] ?? '';
    $exists = is_file($path);
    $size = $exists ? filesize($path) : 0;
    printf("\n--- %s\n    path=%s\n    existe=%s  tamaño=%s bytes\n", $name, $path, $exists ? 'SI' : 'NO', number_format($size));

    if (!$exists || 'pdf' !== strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        continue;
    }

    $bytes = file_get_contents($path);
    printf("    magic=%s\n", substr($bytes, 0, 8));

    foreach ([['con límite del módulo', true], ['sin límite de memoria', false]] as [$label, $limited]) {
        try {
            $config = new PdfConfig();
            $config->setRetainImageContent(false);
            if ($limited) {
                $config->setDecodeMemoryLimit(8 * 1024 * 1024);
            }
            $parser = new PdfParser([], $config);
            $text = (string) $parser->parseContent($bytes)->getText();
            printf("    [%s] OK -> %d chars de texto\n", $label, strlen(trim($text)));
        } catch (\Throwable $e) {
            printf("    [%s] FALLO -> %s: %s\n", $label, get_class($e), $e->getMessage());
        }
    }
}
