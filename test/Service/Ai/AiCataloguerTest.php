<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Content\ContentExtractor;
use PHPUnit\Framework\TestCase;

/**
 * TDD del orquestador: extrae contenido seguro, lo destila en una ficha fiel
 * (ADR-0011) y fusiona las propuestas del clasificador curricular y el de ejes
 * en un único mapa para pre-rellenar el panel de 4a. Usa el ContentExtractor real
 * (puro) y clasificadores falsos.
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

    private function distiller(string $ficha): ContextDistiller
    {
        return new ContextDistiller(new FakeLlmClient([$ficha]), new PromptBuilder());
    }

    public function testMergesProposalsAndReportsContent(): void
    {
        $curricular = new FakeClassifier(['schema:about' => [20], 'lrmi:teaches' => [30]]);
        $tags = new FakeClassifier(['dcterms:relation' => [50]]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->distiller('Ficha: álgebra y ecuaciones'),
            $curricular,
            $tags
        );

        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido sobre álgebra.');

        $out = $cataloguer->propose('Título: Recurso de mates', [['path' => $file, 'name' => 'nota.txt']]);

        $this->assertSame(
            ['schema:about' => [20], 'lrmi:teaches' => [30], 'dcterms:relation' => [50]],
            $out['alignment']
        );
        $this->assertFalse($out['content']['truncated']);
        $this->assertContains('nota.txt', $out['content']['sources']);
        // La ficha destilada queda registrada para el panel de debug.
        $this->assertSame('Ficha: álgebra y ecuaciones', $out['debug']['ficha']);

        // Ambos clasificadores recibieron el contexto (con la ficha ya destilada).
        $this->assertStringContainsString('Recurso de mates', $curricular->received[0]);
        $this->assertStringContainsString('álgebra', $curricular->received[0]);
        $this->assertStringContainsString('álgebra', $tags->received[0]);
    }

    public function testDistillationFeedsFichaIntoContext(): void
    {
        // La ficha del destilador entra en el ItemContext (visible en el texto fino
        // del debug, que la incluye junto al crudo).
        $curricular = new FakeClassifier([]);
        $tags = new FakeClassifier([]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->distiller('FICHA DESTILADA'),
            $curricular,
            $tags
        );

        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido sobre la célula.');

        $out = $cataloguer->propose('Título: La célula', [['path' => $file, 'name' => 'nota.txt']]);

        $this->assertSame('FICHA DESTILADA', $out['debug']['ficha']);
        $this->assertStringContainsString('FICHA DESTILADA', $out['debug']['content_text']);
        $this->assertNotEmpty($out['debug']['distillation']);
    }

    public function testEmptyContentSkipsClassifiersAndDistiller(): void
    {
        $curricular = new FakeClassifier(['schema:about' => [20]]);
        $tags = new FakeClassifier(['dcterms:relation' => [50]]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->distiller('no debería llamarse'),
            $curricular,
            $tags
        );

        $out = $cataloguer->propose('', []);

        $this->assertSame([], $out['alignment']);
        $this->assertTrue($out['content']['empty']);
        // Sin contenido no se gasta ni un token: ni destilador ni clasificadores.
        $this->assertSame([], $curricular->received);
        $this->assertSame([], $tags->received);
        $this->assertSame('', $out['debug']['ficha']);
        $this->assertSame([], $out['debug']['distillation']);
    }
}

