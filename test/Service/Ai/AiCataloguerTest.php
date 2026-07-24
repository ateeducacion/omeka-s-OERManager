<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\ContextDistiller;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Test\Service\Content\ContentExtractorTest;
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

    /** Visión apagada por defecto (off-by-default, ADR-0011): no-op sin red. */
    private function vision(?FakeLlmClient $llm = null, bool $enabled = false): MediaVisionExtractor
    {
        // minImageBytes bajo para no descartar ficheros pequeños de los tests.
        return new MediaVisionExtractor($llm ?? new FakeLlmClient(), new PromptBuilder(), $enabled, 3, 10);
    }

    public function testMergesProposalsAndReportsContent(): void
    {
        $curricular = new FakeClassifier(['schema:about' => [20], 'lrmi:teaches' => [30]]);
        $tags = new FakeClassifier(['dcterms:relation' => [50]]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
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

    public function testExposesJustificationsFromCurricular(): void
    {
        // TASK-023: el orquestador expone las justificaciones del clasificador
        // curricular (saberes/criterios) en el resultado.
        $curricular = new FakeClassifier(
            ['lrmi:teaches' => [30]],
            ['lrmi:teaches' => [30 => 'trata el álgebra']]
        );
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
            $this->distiller('Ficha'),
            $curricular,
            new FakeClassifier([])
        );
        $file = $this->dir . '/n.txt';
        file_put_contents($file, 'Contenido de álgebra.');

        $out = $cataloguer->propose('Título: X', [['path' => $file, 'name' => 'n.txt']]);

        $this->assertSame(['lrmi:teaches' => [30 => 'trata el álgebra']], $out['justifications']);
    }

    public function testJustificationsEmptyWhenClassifierLacksThem(): void
    {
        // Un clasificador sin getJustifications (como TagClassifier) no rompe.
        $curricular = new class implements \OERManager\Service\Ai\ClassifierInterface {
            public function classify(\OERManager\Service\Content\ItemContext $context): array
            {
                return ['schema:about' => [20]];
            }
        };
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
            $this->distiller('Ficha'),
            $curricular,
            new FakeClassifier([])
        );
        $file = $this->dir . '/n.txt';
        file_put_contents($file, 'Contenido.');

        $out = $cataloguer->propose('Título: X', [['path' => $file, 'name' => 'n.txt']]);

        $this->assertSame([], $out['justifications']);
    }

    public function testDistillationFeedsFichaIntoContext(): void
    {
        // La ficha del destilador entra en el ItemContext (visible en el texto fino
        // del debug, que la incluye junto al crudo).
        $curricular = new FakeClassifier([]);
        $tags = new FakeClassifier([]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
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
            $this->vision(),
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

    public function testVisionDescriptionsFeedTheContext(): void
    {
        // Item solo con una imagen (sin texto): la visión es la única señal y debe
        // llegar al contexto y, por tanto, a los clasificadores (ADR-0011).
        $curricular = new FakeClassifier(['schema:about' => [20]]);
        $tags = new FakeClassifier([]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(new FakeLlmClient(['Infografía del ciclo del agua']), true),
            $this->distiller('Ficha: ciclo del agua'),
            $curricular,
            $tags
        );

        $image = $this->dir . '/ciclo.png';
        file_put_contents($image, str_repeat('x', 5000));

        $out = $cataloguer->propose('', [], [
            ['path' => $image, 'mediaType' => 'image/png', 'name' => 'ciclo.png', 'size' => 5000],
        ]);

        $this->assertFalse($out['content']['empty']);
        $this->assertStringContainsString('Infografía del ciclo del agua', $out['debug']['content_text']);
        $this->assertStringContainsString('Infografía del ciclo del agua', $curricular->received[0]);
        $this->assertNotEmpty($out['debug']['vision']);
        $this->assertSame(['schema:about' => [20]], $out['alignment']);
    }

    public function testScannedPdfIsRescuedThroughVision(): void
    {
        // Un PDF sin capa de texto (escaneado): el ContentExtractor lo salta
        // (pdf_unreadable) y el rescate lo enruta a la visión como documento.
        $curricular = new FakeClassifier([]);
        $tags = new FakeClassifier([]);
        $visionLlm = new FakeLlmClient(['Examen escaneado de matemáticas']);
        $vision = $this->vision($visionLlm, true);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $vision,
            $this->distiller('Ficha'),
            $curricular,
            $tags
        );

        $pdf = $this->dir . '/escaneado.pdf';
        file_put_contents($pdf, 'no es un pdf real, sin capa de texto');

        $out = $cataloguer->propose('', [
            ['path' => $pdf, 'mediaType' => 'application/pdf', 'name' => 'escaneado.pdf'],
        ]);

        // El PDF ilegible se reportó como saltado y su señal se rescató por visión.
        $this->assertStringContainsString('Examen escaneado de matemáticas', $out['debug']['content_text']);
        $this->assertSame(1, $out['debug']['vision'][0]['pdfs']);
        $this->assertCount(1, $visionLlm->calls);
    }

    /**
     * TASK-024(b): en una plataforma sin `//TRANSLIT` (musl) el PDF vuelve vacío
     * aunque tenga capa de texto. Ese motivo debe rescatarse por visión igual
     * que `pdf_empty`, o el item se queda sin señal alguna.
     */
    public function testPdfLostToBrokenIconvIsRescuedThroughVision(): void
    {
        $visionLlm = new FakeLlmClient(['Lámina de figuras planas']);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(['iconv_translit_supported' => false]),
            $this->vision($visionLlm, true),
            $this->distiller('Ficha'),
            new FakeClassifier([]),
            new FakeClassifier([])
        );

        $pdf = $this->dir . '/winansi.pdf';
        file_put_contents($pdf, ContentExtractorTest::minimalPdf(''));

        $out = $cataloguer->propose('', [
            ['path' => $pdf, 'mediaType' => 'application/pdf', 'name' => 'winansi.pdf'],
        ]);

        $this->assertSame('pdf_iconv_unsupported', $out['content']['skipped']['winansi.pdf'] ?? null);
        $this->assertSame(1, $out['debug']['vision'][0]['pdfs']);
        $this->assertStringContainsString('Lámina de figuras planas', $out['debug']['content_text']);
    }

    public function testVisionDisabledByDefaultSkipsTheLlm(): void
    {
        $visionLlm = new FakeLlmClient(['no debería llamarse']);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision($visionLlm, false),
            $this->distiller('Ficha'),
            new FakeClassifier([]),
            new FakeClassifier([])
        );

        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido textual.');
        $image = $this->dir . '/foto.png';
        file_put_contents($image, str_repeat('x', 5000));

        $cataloguer->propose('Título: X', [['path' => $file, 'name' => 'nota.txt']], [
            ['path' => $image, 'mediaType' => 'image/png', 'name' => 'foto.png', 'size' => 5000],
        ]);

        $this->assertSame([], $visionLlm->calls);
    }

    // --- TASK-025: rescate confirmado de PDF grandes ---

    /** Extractor que marca todo PDF como pdf_too_large (tope de parseo minúsculo). */
    private function oversizeCataloguer(FakeLlmClient $visionLlm, bool $enabled, int $catCap): AiCataloguer
    {
        return new AiCataloguer(
            new ContentExtractor(['max_pdf_bytes' => 10]),
            new MediaVisionExtractor($visionLlm, new PromptBuilder(), $enabled, 3, 10, 5242880, 1024, null, 100000),
            $this->distiller('Ficha'),
            new FakeClassifier([]),
            new FakeClassifier([]),
            $catCap
        );
    }

    private function bigPdf(string $name, int $claimedSize): array
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, '%PDF-1.4 binario que pasa del tope de parseo');
        return ['path' => $path, 'mediaType' => 'application/pdf', 'name' => $name, 'size' => $claimedSize];
    }

    public function testConfirmablePdfInAskModeReturnsNeedsConfirmationWithoutAnyLlmCall(): void
    {
        $visionLlm = new FakeLlmClient(['no debería llegar']);
        $distillLlm = new FakeLlmClient(['tampoco']);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(['max_pdf_bytes' => 10]),
            new MediaVisionExtractor($visionLlm, new PromptBuilder(), true, 3, 10, 5242880, 1024, null, 100000),
            new ContextDistiller($distillLlm, new PromptBuilder()),
            new FakeClassifier([]),
            new FakeClassifier([]),
            33554432
        );

        $out = $cataloguer->propose('', [$this->bigPdf('grande.pdf', 25165824)], [], 'ask');

        $names = array_column($out['needs_confirmation']['confirmable'], 'name');
        $this->assertSame(['grande.pdf'], $names);
        // El corte ocurre ANTES de cualquier llamada: ni destilación ni visión.
        $this->assertSame([], $visionLlm->calls);
        $this->assertSame([], $distillLlm->calls);
        $this->assertSame([], $out['alignment']);
    }

    public function testConfirmablePdfWithIncludeReachesVision(): void
    {
        $visionLlm = new FakeLlmClient(['Contenido del PDF grande.']);
        $cataloguer = $this->oversizeCataloguer($visionLlm, true, 33554432);

        $out = $cataloguer->propose('', [$this->bigPdf('grande.pdf', 25165824)], [], 'include');

        $this->assertArrayNotHasKey('needs_confirmation', $out);
        $this->assertSame(1, $out['debug']['vision'][0]['pdfs']);
        $this->assertCount(1, $visionLlm->calls);
    }

    public function testConfirmablePdfWithSkipDoesNotReachVisionAndProposeCompletes(): void
    {
        $visionLlm = new FakeLlmClient(['no debería llegar']);
        $cataloguer = $this->oversizeCataloguer($visionLlm, true, 33554432);

        $out = $cataloguer->propose('Título con señal', [$this->bigPdf('grande.pdf', 25165824)], [], 'skip');

        $this->assertArrayNotHasKey('needs_confirmation', $out);
        $this->assertSame([], $visionLlm->calls);
        $this->assertSame('pdf_too_large', $out['content']['skipped']['grande.pdf'] ?? null);
    }

    public function testPdfOverConfirmationCapIsReportedButNotOffered(): void
    {
        // El PDF supera el tope de confirmación (cap del cataloguer = 20): fuera
        // de alcance. No se ofrece confirmación; el propose sigue de largo.
        $visionLlm = new FakeLlmClient(['no']);
        $cataloguer = $this->oversizeCataloguer($visionLlm, true, 20);

        $out = $cataloguer->propose('Título', [$this->bigPdf('enorme.pdf', 41943040)], [], 'ask');

        $this->assertArrayNotHasKey('needs_confirmation', $out);
        $names = array_column($out['content']['too_large_pdfs'], 'name');
        $this->assertSame(['enorme.pdf'], $names);
    }

    public function testConfirmablePdfNotOfferedWhenVisionDisabled(): void
    {
        // Doble puerta: sin visión no hay rescate posible → no se pregunta.
        $visionLlm = new FakeLlmClient(['no']);
        $cataloguer = $this->oversizeCataloguer($visionLlm, false, 33554432);

        $out = $cataloguer->propose('Título', [$this->bigPdf('grande.pdf', 25165824)], [], 'ask');

        $this->assertArrayNotHasKey('needs_confirmation', $out);
    }

    public function testUnknownDecisionIsTreatedAsAsk(): void
    {
        $visionLlm = new FakeLlmClient(['no']);
        $cataloguer = $this->oversizeCataloguer($visionLlm, true, 33554432);

        $out = $cataloguer->propose('', [$this->bigPdf('grande.pdf', 25165824)], [], 'lo-que-sea');

        $this->assertArrayHasKey('needs_confirmation', $out);
    }

    public function testClassifyOversizePdfsSplitsAtTheCap(): void
    {
        $cataloguer = $this->oversizeCataloguer(new FakeLlmClient(), true, 33554432);
        $files = [
            ['path' => '/x/a.pdf', 'name' => 'borde.pdf', 'size' => 33554432],  // == cap → confirmable
            ['path' => '/x/b.pdf', 'name' => 'pasa.pdf', 'size' => 33554433],   // cap+1 → fuera
        ];
        // 'interno.pdf' está saltado pero no tiene fichero (entrada interna de un
        // ZIP, sin ruta propia) → se omite de ambas listas.
        $skipped = [
            'borde.pdf' => 'pdf_too_large',
            'pasa.pdf' => 'pdf_too_large',
            'interno.pdf' => 'pdf_too_large',
        ];

        $out = $cataloguer->classifyOversizePdfs($files, $skipped);

        $this->assertSame(['borde.pdf'], array_column($out['confirmable'], 'name'));
        $this->assertSame(['pasa.pdf'], array_column($out['too_large'], 'name'));
    }

    // --- TASK-020: progreso por fases y cancelación ---

    public function testReportsPhasesToProgressReporter(): void
    {
        $reporter = new RecordingProgressReporter();
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
            $this->distiller('Ficha'),
            new FakeClassifier(['schema:about' => [20]]),
            new FakeClassifier([])
        );
        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido sobre álgebra.');

        $cataloguer->propose('Meta', [['path' => $file, 'name' => 'nota.txt']], [], 'ask', $reporter);

        $this->assertContains('Extrayendo contenido', $reporter->steps);
        $this->assertContains('Destilando ficha', $reporter->steps);
        $this->assertContains('Clasificación curricular', $reporter->steps);
        $this->assertContains('Ejes temáticos', $reporter->steps);
    }

    public function testStopBetweenPhasesThrowsAndProducesNoProposal(): void
    {
        // Parar tras el primer report(): el propose debe abortar sin clasificar.
        $reporter = new RecordingProgressReporter(stopAfter: 1);
        $curricular = new FakeClassifier(['schema:about' => [20]]);
        $cataloguer = new AiCataloguer(
            new ContentExtractor(),
            $this->vision(),
            $this->distiller('Ficha'),
            $curricular,
            new FakeClassifier([])
        );
        $file = $this->dir . '/nota.txt';
        file_put_contents($file, 'Contenido.');

        $this->expectException(\OERManager\Service\Ai\JobStoppedException::class);
        $cataloguer->propose('Meta', [['path' => $file, 'name' => 'nota.txt']], [], 'ask', $reporter);
    }
}

final class RecordingProgressReporter implements \OERManager\Service\Ai\ProgressReporter
{
    /** @var string[] */
    public array $steps = [];

    public function __construct(private int $stopAfter = PHP_INT_MAX)
    {
    }

    public function report(string $step, int $done, int $total): void
    {
        $this->steps[] = $step;
    }

    public function shouldStop(): bool
    {
        return count($this->steps) >= $this->stopAfter;
    }
}

