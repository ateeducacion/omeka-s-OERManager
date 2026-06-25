<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Content\ContentExtractor;
use PHPUnit\Framework\TestCase;

/**
 * TDD del orquestador: extrae contenido seguro y fusiona las propuestas del
 * clasificador curricular y el de ejes en un único mapa para pre-rellenar el
 * panel de 4a. Usa el ContentExtractor real (puro) y clasificadores falsos.
 */
final class AiCataloguerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_cat_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testMergesProposalsAndReportsContent(): void
    {
        $curricular = new FakeClassifier(['schema:about' => [20], 'lrmi:teaches' => [30]]);
        $tags = new FakeClassifier(['dcterms:relation' => [50]]);
        $cataloguer = new AiCataloguer(new ContentExtractor(), $curricular, $tags);

        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido sobre álgebra.');

        $out = $cataloguer->propose('Título: Recurso de mates', [['path' => $file, 'name' => 'nota.txt']]);

        $this->assertSame(
            ['schema:about' => [20], 'lrmi:teaches' => [30], 'dcterms:relation' => [50]],
            $out['alignment']
        );
        $this->assertFalse($out['content']['truncated']);
        $this->assertContains('nota.txt', $out['content']['sources']);

        // Ambos clasificadores recibieron el contenido extraído (metadatos + medio).
        $this->assertStringContainsString('Recurso de mates', $curricular->received[0]);
        $this->assertStringContainsString('álgebra', $curricular->received[0]);
        $this->assertStringContainsString('álgebra', $tags->received[0]);
    }

    public function testEmptyContentSkipsClassifiers(): void
    {
        $curricular = new FakeClassifier(['schema:about' => [20]]);
        $tags = new FakeClassifier(['dcterms:relation' => [50]]);
        $cataloguer = new AiCataloguer(new ContentExtractor(), $curricular, $tags);

        $out = $cataloguer->propose('', []);

        $this->assertSame([], $out['alignment']);
        $this->assertTrue($out['content']['empty']);
        // Sin contenido no se gasta ni un token: los clasificadores no se invocan.
        $this->assertSame([], $curricular->received);
        $this->assertSame([], $tags->received);
    }
}
