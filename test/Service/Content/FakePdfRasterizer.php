<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\PdfRasterizerInterface;

/**
 * Rasterizador falso: devuelve las páginas encoladas y registra las llamadas, para
 * probar en host el camino de visión sin ext-imagick (que solo existe en el
 * contenedor). Una cola vacía simula «no se pudo rasterizar» (sin Imagick o PDF
 * ilegible), que es la condición de respaldo al bloque `document`.
 */
final class FakePdfRasterizer implements PdfRasterizerInterface
{
    /** @var array<int,array{path:string,name:string}> */
    public array $calls = [];

    /** @param string[] $pages contenido binario de cada página rasterizada */
    public function __construct(private array $pages = [])
    {
    }

    public function rasterize(string $path, string $name): array
    {
        $this->calls[] = ['path' => $path, 'name' => $name];

        $out = [];
        foreach ($this->pages as $i => $blob) {
            $out[] = [
                'data' => $blob,
                'mediaType' => 'image/jpeg',
                'name' => $name . ' (p. ' . ($i + 1) . ')',
                'size' => strlen($blob),
            ];
        }
        return $out;
    }
}
