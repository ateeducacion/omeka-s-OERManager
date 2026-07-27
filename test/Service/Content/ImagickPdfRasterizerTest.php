<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\ImagickPdfRasterizer;
use PHPUnit\Framework\TestCase;

/**
 * TDD del rasterizador (TASK-026). El render real necesita ext-imagick, que solo
 * existe en el contenedor (el host no la tiene): aquí se prueba el contrato que SÍ
 * es verificable en host —degradación limpia sin la extensión, con PDF ilegible o
 * inexistente— y el render real se verifica en el contenedor.
 *
 * Que devuelva `[]` en vez de lanzar es parte del contrato: es la condición con la
 * que MediaVisionExtractor decide caer al bloque `document` nativo.
 */
final class ImagickPdfRasterizerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_rast_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMissingFileYieldsNoPages(): void
    {
        $rasterizer = new ImagickPdfRasterizer();

        $this->assertSame([], $rasterizer->rasterize($this->dir . '/no-existe.pdf', 'no-existe.pdf'));
    }

    public function testUnreadableContentYieldsNoPagesInsteadOfThrowing(): void
    {
        // Sin ext-imagick (host) o con un PDF que Imagick no sabe abrir, el
        // contrato es el mismo: cero páginas y ninguna excepción que aborte el
        // propose. El extractor lo lee como «cae al camino nativo».
        $path = $this->dir . '/roto.pdf';
        file_put_contents($path, 'esto no es un PDF');
        $rasterizer = new ImagickPdfRasterizer();

        $this->assertSame([], $rasterizer->rasterize($path, 'roto.pdf'));
    }

    public function testPageCapIsNeverExceeded(): void
    {
        // El tope de páginas es la guarda de coste (4 por defecto, decisión del
        // propietario): un escaneado de 200 páginas no puede convertirse en 200
        // bloques de imagen. Sin Imagick el resultado es vacío, que también lo cumple.
        $path = $this->dir . '/largo.pdf';
        file_put_contents($path, '%PDF-1.4');
        $rasterizer = new ImagickPdfRasterizer(maxPages: 2);

        $this->assertLessThanOrEqual(2, count($rasterizer->rasterize($path, 'largo.pdf')));
    }

    public function testDefaultPageCapIsFour(): void
    {
        $this->assertSame(4, ImagickPdfRasterizer::DEFAULT_MAX_PAGES);
    }
}
