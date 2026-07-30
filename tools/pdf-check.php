<?php

/**
 * Sonda de extracción de texto de PDF (TASK-024b).
 *
 * Comprueba si ESTE despliegue puede leer el texto de los PDF del catálogo. La
 * plataforma importa: `smalot/pdfparser` convierte con
 * `iconv(..., 'UTF-8//TRANSLIT//IGNORE', ...)` y en musl (Alpine) esa conversión
 * devuelve `false`, así que el texto se pierde —entero o en parte— sin que nada
 * lo señale. `WinAnsiEncoding`/CP1252 es la codificación más común en PDF, de
 * modo que el daño no es un caso raro.
 *
 * Se apoya en el `ContentExtractor` real del módulo, no en el parser en crudo:
 * lo que interesa medir es lo que verá el catalogador IA, con sus topes y sus
 * motivos de descarte.
 *
 * Uso:
 *   php tools/pdf-check.php [ruta]      # ruta = fichero .pdf o directorio
 *   make pdf-check                      # sobre el directorio estándar de Omeka
 *
 * Códigos de salida:
 *   0  la plataforma sabe leer PDF (aunque algún fichero concreto se descarte)
 *   1  la plataforma NO sabe (iconv sin //TRANSLIT): todos los PDF degradados
 *   2  error de uso (ruta inexistente, sin autoload)
 *
 * OJO con la pérdida PARCIAL: un PDF puede dar `OK` con una fracción del texto.
 * El motivo `pdf_iconv_unsupported` solo salta cuando el resultado queda vacío
 * del todo, así que la sonda de plataforma de la cabecera es la señal fiable,
 * no el recuento por fichero.
 *
 * Los auxiliares van como closures a propósito: PSR-12 no admite que un mismo
 * fichero declare símbolos y ejecute lógica, y esto es un script, no una
 * biblioteca.
 */

declare(strict_types=1);

use OERManager\Service\Content\ContentExtractor;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "ERROR: falta vendor/autoload.php. Ejecuta `composer install`.\n");
    exit(2);
}
require $autoload;

/** Directorio de ficheros de una instalación estándar de Omeka-S. */
$omekaFilesDir = '/var/www/html/volume/files/original';

/** Tope de ficheros analizados: esto es una sonda, no un informe. */
$limit = 25;

/** `memory_limit` por debajo de 256 MB deja los PDF grandes fuera de alcance. */
$memoryIsLow = static function (string $value): bool {
    if ('-1' === $value) {
        return false;
    }
    $bytes = (int) $value;
    $unit = strtoupper(substr($value, -1));
    if ('G' === $unit) {
        $bytes *= 1024 * 1024 * 1024;
    } elseif ('M' === $unit) {
        $bytes *= 1024 * 1024;
    } elseif ('K' === $unit) {
        $bytes *= 1024;
    }
    return $bytes < 256 * 1024 * 1024;
};

$shorten = static fn (string $text, int $max): string
    => strlen($text) <= $max ? $text : substr($text, 0, $max - 1) . '…';

$target = $argv[1] ?? $omekaFilesDir;

echo "== Sonda de extracción de PDF (TASK-024b) ==\n\n";

// ---------------------------------------------------------------------------
// 1. Plataforma. Es la parte que decide, y no depende de que haya ficheros.
// ---------------------------------------------------------------------------
$translitOk = false !== @iconv('CP1252', 'UTF-8//TRANSLIT//IGNORE', 'a');
$memoryLimit = (string) ini_get('memory_limit');
$lowMemory = $memoryIsLow($memoryLimit);

printf("PHP          : %s\n", PHP_VERSION);
printf("iconv        : %s\n", ICONV_IMPL);
printf("//TRANSLIT   : %s\n", $translitOk ? 'SÍ' : 'NO  <-- los PDF se leerán mal');
printf("memory_limit : %s%s\n\n", $memoryLimit, $lowMemory ? '  <-- bajo, ver nota final' : '');

if (!$translitOk) {
    echo "DIAGNÓSTICO: esta plataforma NO puede convertir CP1252 con //TRANSLIT.\n";
    echo "Todo PDF con WinAnsiEncoding —la codificación más común— perderá texto,\n";
    echo "entera o parcialmente, sin aviso. El arreglo es de imagen base (usar una\n";
    echo "con glibc), no del módulo. Ver docs/referencia/despliegue-pruebas-pdf.md\n\n";
}

// ---------------------------------------------------------------------------
// 2. Ficheros concretos, si los hay.
// ---------------------------------------------------------------------------
if (is_file($target)) {
    $files = [$target];
} elseif (is_dir($target)) {
    $files = glob(rtrim($target, '/') . '/*.pdf') ?: [];
    sort($files);
} else {
    fwrite(STDERR, sprintf("ERROR: «%s» no existe.\n", $target));
    exit(2);
}

if ([] === $files) {
    printf("Sin PDF que analizar en «%s».\n", $target);
    echo "Pasa una ruta como argumento si tus ficheros están en otro sitio.\n";
    exit($translitOk ? 0 : 1);
}

$found = count($files);
$files = array_slice($files, 0, $limit);
printf("Analizando %d de %d PDF en «%s»:\n\n", count($files), $found, $target);
printf("%-38s %9s  %s\n", 'FICHERO', 'CHARS', 'ESTADO');

$extractor = new ContentExtractor();
$chars = 0;
$byStatus = [];

foreach ($files as $path) {
    $name = basename($path);
    $result = $extractor->extract('', [['path' => $path, 'name' => $name]]);
    $skipped = $result->skipped();
    $status = [] === $skipped ? 'OK' : implode(',', array_values($skipped));
    $length = strlen($result->text());

    $chars += $length;
    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
    printf("%-38s %9d  %s\n", $shorten($name, 38), $length, $status);
}

echo "\nRESUMEN\n";
printf("  caracteres extraídos : %d\n", $chars);
foreach ($byStatus as $status => $count) {
    printf("  %-21s: %d\n", $status, $count);
}

if (isset($byStatus['pdf_iconv_unsupported'])) {
    echo "\n  `pdf_iconv_unsupported` confirma el fallo de plataforma sobre datos reales.\n";
}
if (isset($byStatus['pdf_too_large'])) {
    echo "\n  `pdf_too_large` NO es un fallo de plataforma: es el tope propio del módulo\n";
    echo "  (`max_pdf_bytes`, 20 MB por omisión). Ese REA se cataloga sin su contenido.\n";
}
if ($lowMemory) {
    echo "\n  NOTA: con un `memory_limit` bajo, un PDF grande aborta el proceso con un\n";
    echo "  fatal de memoria que NO es capturable y que no aparece como descarte.\n";
}

exit($translitOk ? 0 : 1);
