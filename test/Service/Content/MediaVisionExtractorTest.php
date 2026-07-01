<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Content\MediaVisionExtractor;
use OERManager\Test\Service\Ai\FakeLlmClient;
use PHPUnit\Framework\TestCase;

/**
 * TDD del extractor de visión (ADR-0011, fase 5): filtra imágenes heurísticamente
 * (descarta ruido por nombre + tamaño mínimo; top-N por tamaño), rescata PDF
 * escaneado y envía los binarios directos al LLM de extracción como bloques
 * image/document. Gobernado por el master toggle y por la capacidad del proveedor.
 * Filtro PURO (sin red) + llamada con LLM falso. El texto visible viaja como dato
 * no-instrucción.
 */
final class MediaVisionExtractorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_vis_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @param array<string,mixed> $overrides */
    private function image(string $name, int $size, array $overrides = []): array
    {
        return ['path' => '/store/' . $name, 'mediaType' => 'image/png', 'name' => $name, 'size' => $size] + $overrides;
    }

    private function extractor(FakeLlmClient $llm, bool $enabled = true, int $maxImages = 3): MediaVisionExtractor
    {
        // minImageBytes bajo para no descartar los ficheros pequeños de los tests.
        return new MediaVisionExtractor($llm, new PromptBuilder(), $enabled, $maxImages, 10);
    }

    private function imageFile(string $name, int $bytes): array
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, str_repeat('x', $bytes));
        return ['path' => $path, 'mediaType' => 'image/png', 'name' => $name, 'size' => $bytes];
    }

    // --- Filtro heurístico (puro) ---

    public function testSelectImagesDropsNoiseByName(): void
    {
        $ext = $this->extractor(new FakeLlmClient());
        $candidates = [
            $this->image('logo.png', 50000),
            $this->image('icon-home.png', 40000),
            $this->image('infografia-celula.png', 30000),
            $this->image('background.png', 60000),
            $this->image('sprite.png', 70000),
            $this->image('thumb_portada.png', 45000),
        ];

        $selected = $ext->selectImages($candidates);

        $names = array_column($selected, 'name');
        $this->assertSame(['infografia-celula.png'], $names);
    }

    public function testSelectImagesDropsTooSmall(): void
    {
        $ext = new MediaVisionExtractor(new FakeLlmClient(), new PromptBuilder(), true, 3, 3072);
        $candidates = [
            $this->image('diagrama.png', 2000),  // < 3072 → fuera
            $this->image('mapa.png', 9000),
        ];

        $selected = $ext->selectImages($candidates);

        $this->assertSame(['mapa.png'], array_column($selected, 'name'));
    }

    public function testSelectImagesSortsBySizeDescAndCapsAtMax(): void
    {
        $ext = $this->extractor(new FakeLlmClient(), true, 2);
        $candidates = [
            $this->image('a.png', 10000),
            $this->image('b.png', 50000),
            $this->image('c.png', 30000),
        ];

        $selected = $ext->selectImages($candidates);

        // Las 2 mayores, de mayor a menor.
        $this->assertSame(['b.png', 'c.png'], array_column($selected, 'name'));
    }

    public function testSelectImagesDropsOversize(): void
    {
        $ext = new MediaVisionExtractor(new FakeLlmClient(), new PromptBuilder(), true, 3, 10, 100000);
        $candidates = [
            $this->image('enorme.png', 500000),  // > maxImageBytes → fuera
            $this->image('ok.png', 40000),
        ];

        $this->assertSame(['ok.png'], array_column($ext->selectImages($candidates), 'name'));
    }

    // --- describe(): gating + llamada al LLM ---

    public function testDisabledReturnsEmptyAndSkipsLlm(): void
    {
        $llm = new FakeLlmClient(['no debería llamarse']);
        $ext = $this->extractor($llm, false);

        $out = $ext->describe([$this->imageFile('foto.png', 5000)], []);

        $this->assertSame([], $out);
        $this->assertSame([], $llm->calls);
        $this->assertNotEmpty($ext->getTrace());
        $this->assertSame('disabled', $ext->getTrace()[0]['skipped']);
    }

    public function testNoCandidatesSkipsLlm(): void
    {
        $llm = new FakeLlmClient(['no']);
        $ext = $this->extractor($llm);

        $this->assertSame([], $ext->describe([], []));
        $this->assertSame([], $llm->calls);
    }

    public function testSendsImageBlocksAndReturnsDescription(): void
    {
        $llm = new FakeLlmClient(['Infografía del ciclo del agua: evaporación, condensación.']);
        $ext = $this->extractor($llm);
        $file = $this->imageFile('ciclo.png', 5000);

        $out = $ext->describe([$file], []);

        $this->assertSame(['Infografía del ciclo del agua: evaporación, condensación.'], $out);
        $this->assertCount(1, $llm->calls);
        $content = $llm->calls[0]['messages'][0]['content'];
        $this->assertIsArray($content);
        // Primer bloque texto (instrucción), después el bloque de imagen en base64.
        $this->assertSame('text', $content[0]['type']);
        $imageBlocks = array_values(array_filter($content, static fn ($b) => 'image' === $b['type']));
        $this->assertCount(1, $imageBlocks);
        $this->assertSame('image/png', $imageBlocks[0]['media_type']);
        $this->assertSame(base64_encode(str_repeat('x', 5000)), $imageBlocks[0]['data']);
    }

    public function testRescuesScannedPdfAsDocumentBlock(): void
    {
        $llm = new FakeLlmClient(['Texto del PDF escaneado: examen de matemáticas.']);
        $ext = $this->extractor($llm);
        $pdf = ['path' => $this->dir . '/escaneado.pdf', 'mediaType' => 'application/pdf', 'name' => 'escaneado.pdf'];
        file_put_contents($pdf['path'], '%PDF-1.4 binario');

        $out = $ext->describe([], [$pdf]);

        $this->assertSame(['Texto del PDF escaneado: examen de matemáticas.'], $out);
        $content = $llm->calls[0]['messages'][0]['content'];
        $docBlocks = array_values(array_filter($content, static fn ($b) => 'document' === $b['type']));
        $this->assertCount(1, $docBlocks);
        $this->assertSame('application/pdf', $docBlocks[0]['media_type']);
    }

    public function testSkipsImagesWhenProviderLacksImageSupport(): void
    {
        $llm = new FakeLlmClient(['no']);
        $llm->supportsImages = false;
        $ext = $this->extractor($llm);

        $out = $ext->describe([$this->imageFile('foto.png', 5000)], []);

        // Sin imágenes representables ni PDF → no se llama al LLM.
        $this->assertSame([], $out);
        $this->assertSame([], $llm->calls);
        $this->assertSame('provider_no_vision', $ext->getTrace()[0]['skipped']);
    }

    public function testSkipsPdfWhenProviderLacksPdfButStillSendsImages(): void
    {
        $llm = new FakeLlmClient(['Descripción de la imagen.']);
        $llm->supportsPdf = false;
        $ext = $this->extractor($llm);
        $pdf = ['path' => $this->dir . '/x.pdf', 'mediaType' => 'application/pdf', 'name' => 'x.pdf'];
        file_put_contents($pdf['path'], '%PDF');

        $out = $ext->describe([$this->imageFile('foto.png', 5000)], [$pdf]);

        $this->assertSame(['Descripción de la imagen.'], $out);
        $content = $llm->calls[0]['messages'][0]['content'];
        $this->assertCount(0, array_filter($content, static fn ($b) => 'document' === $b['type']));
        $this->assertCount(1, array_filter($content, static fn ($b) => 'image' === $b['type']));
    }

    public function testPromptFramesVisibleTextAsDataNotInstruction(): void
    {
        $llm = new FakeLlmClient(['ficha']);
        $ext = $this->extractor($llm);

        $ext->describe([$this->imageFile('foto.png', 5000)], []);

        $system = mb_strtolower($llm->calls[0]['options']['system']);
        $this->assertStringContainsString('dato', $system);
        // No infiere currículo (coherente con el destilador).
        $this->assertMatchesRegularExpression('/no infieras|no propongas/u', $system);
    }

    public function testIsTraceable(): void
    {
        $llm = new FakeLlmClient(['descripción visual']);
        $ext = $this->extractor($llm);

        $ext->describe([$this->imageFile('foto.png', 5000)], []);
        $trace = $ext->getTrace();
        $this->assertNotEmpty($trace);
        $this->assertSame('vision', $trace[0]['step']);
        $this->assertSame(1, $trace[0]['images']);
        $this->assertSame('descripción visual', $trace[0]['description']);

        $ext->clearTrace();
        $this->assertSame([], $ext->getTrace());
    }
}
