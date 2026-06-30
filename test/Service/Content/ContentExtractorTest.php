<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\ExtractedContent;
use PHPUnit\Framework\TestCase;

/**
 * TDD adversario del extractor de contenido (spec §6). ZipArchive y
 * smalot/pdfparser están en el vendor del módulo, así que toda la seguridad de
 * la extracción se prueba con zips y PDFs reales creados en el propio test.
 */
final class ContentExtractorTest extends TestCase
{
    /** Directorio temporal propio del test (ficheros con su nombre exacto). */
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_test_' . bin2hex(random_bytes(8));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->dir && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->dir);
        }
        $this->dir = '';
    }

    public function testMetadataAloneIsReturned(): void
    {
        $content = (new ContentExtractor())->extract('Título del REA', []);
        $this->assertInstanceOf(ExtractedContent::class, $content);
        $this->assertStringContainsString('Título del REA', $content->text());
        $this->assertFalse($content->isTruncated());
    }

    public function testPlainTextFileIsIncluded(): void
    {
        $file = $this->tempFile('nota.txt', 'Contenido de la nota didáctica.');
        $content = (new ContentExtractor())->extract('meta', [['path' => $file]]);
        $this->assertStringContainsString('Contenido de la nota didáctica.', $content->text());
        $this->assertContains('nota.txt', $content->sources());
    }

    public function testHtmlMarkupIsStrippedAndScriptRemoved(): void
    {
        $html = '<html><head><style>.x{}</style><script>alert(1)</script></head>'
            . '<body><h1>Geometría</h1><p>Área del triángulo</p></body></html>';
        $file = $this->tempFile('lesson.html', $html);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('Geometría', $content->text());
        $this->assertStringContainsString('Área del triángulo', $content->text());
        $this->assertStringNotContainsString('alert(1)', $content->text());
        $this->assertStringNotContainsString('<h1>', $content->text());
    }

    public function testPdfTextIsExtracted(): void
    {
        $file = $this->tempFile('doc.pdf', self::minimalPdf('Saberes basicos mates'));
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('Saberes basicos mates', $content->text());
    }

    public function testMalformedPdfIsSkippedGracefully(): void
    {
        $file = $this->tempFile('broken.pdf', '%PDF-1.4 garbage not a real pdf');
        $content = (new ContentExtractor())->extract('meta', [['path' => $file]]);
        // No excepción: el PDF roto se salta y los metadatos siguen.
        $this->assertStringContainsString('meta', $content->text());
        $this->assertArrayHasKey('broken.pdf', $content->skipped());
    }

    public function testZipEntryIsExtracted(): void
    {
        $zip = $this->tempZip('package.zip', ['index.html' => '<p>Hola SCORM</p>']);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('Hola SCORM', $content->text());
    }

    public function testZipSlipEntryIsRejected(): void
    {
        $zip = $this->tempZip('evil.zip', [
            '../../../etc/passwd.txt' => 'root:x:0:0',
            'safe.txt' => 'contenido seguro',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('contenido seguro', $content->text());
        $this->assertStringNotContainsString('root:x:0:0', $content->text());
        $this->assertArrayHasKey('../../../etc/passwd.txt', $content->skipped());
    }

    public function testAbsolutePathEntryIsRejected(): void
    {
        $zip = $this->tempZip('abs.zip', ['/etc/shadow.txt' => 'secret']);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringNotContainsString('secret', $content->text());
    }

    public function testNestedZipIsNotRecursed(): void
    {
        $inner = $this->tempZip('inner.zip', ['deep.txt' => 'contenido anidado']);
        $innerBytes = file_get_contents($inner);
        $zip = $this->tempZip('outer.zip', ['payload.zip' => $innerBytes, 'top.txt' => 'nivel superior']);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('nivel superior', $content->text());
        $this->assertStringNotContainsString('contenido anidado', $content->text());
        $this->assertArrayHasKey('payload.zip', $content->skipped());
    }

    public function testZipBombByCompressionRatioIsSkipped(): void
    {
        // 5 MB de ceros comprime con ratio enorme: debe saltarse, no leerse.
        $bomb = str_repeat("\0", 5 * 1024 * 1024);
        $zip = $this->tempZip('bomb.zip', ['bomb.txt' => $bomb, 'real.txt' => 'dato real']);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('dato real', $content->text());
        $this->assertArrayHasKey('bomb.txt', $content->skipped());
        $this->assertLessThan(1024 * 1024, strlen($content->text()));
    }

    public function testTooManyEntriesAreCapped(): void
    {
        $entries = [];
        for ($i = 0; $i < 50; $i++) {
            $entries["file$i.txt"] = "entrada $i";
        }
        $extractor = new ContentExtractor(['max_zip_entries' => 10]);
        $zip = $this->tempZip('many.zip', $entries);
        $content = $extractor->extract('', [['path' => $zip]]);
        // No revienta; respeta el tope (no se leen las 50 entradas).
        $this->assertNotEmpty($content->skipped());
    }

    public function testNonWhitelistedExtensionInZipIsSkipped(): void
    {
        $zip = $this->tempZip('mix.zip', ['run.exe' => 'MZ binario', 'ok.txt' => 'texto válido']);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('texto válido', $content->text());
        $this->assertStringNotContainsString('MZ binario', $content->text());
    }

    public function testTotalContentIsTruncatedToBudget(): void
    {
        $big = str_repeat('palabra ', 5000); // ~40k chars
        $file = $this->tempFile('big.txt', $big);
        $extractor = new ContentExtractor(['max_total_chars' => 1000]);
        $content = $extractor->extract('meta', [['path' => $file]]);
        $this->assertTrue($content->isTruncated());
        $this->assertLessThanOrEqual(1000, mb_strlen($content->text()));
    }

    public function testUnreadableFileIsSkipped(): void
    {
        $content = (new ContentExtractor())->extract('meta', [['path' => '/no/existe/aqui.txt', 'name' => 'aqui.txt']]);
        $this->assertStringContainsString('meta', $content->text());
        $this->assertArrayHasKey('aqui.txt', $content->skipped());
    }

    public function testJsonContentValuesAreExtracted(): void
    {
        $json = json_encode([
            'title' => 'Partes de la célula',
            'pages' => [
                ['text' => 'Identificación de la célula como unidad estructural y funcional.'],
                ['text' => 'Diferenciación entre célula procariota y eucariota.'],
            ],
        ]);
        $file = $this->tempFile('project.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('unidad estructural y funcional', $content->text());
        $this->assertStringContainsString('procariota y eucariota', $content->text());
        $this->assertContains('project.json', $content->sources());
    }

    public function testJsonTechnicalNoiseIsFiltered(): void
    {
        $json = json_encode([
            'id' => '581_573_215_0',
            'asset' => 'resources/celula_graficos_30.jpg',
            'url' => 'https://example.com/x',
            'hash' => 'a630fdcd12abcdef',
            'cssClass' => 'panel_view',
            'body' => 'Valoración de la importancia de la célula como unidad de vida.',
        ]);
        $file = $this->tempFile('p.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('importancia de la célula', $content->text());
        $this->assertStringNotContainsString('581_573_215_0', $content->text());
        $this->assertStringNotContainsString('celula_graficos_30.jpg', $content->text());
        $this->assertStringNotContainsString('example.com', $content->text());
        $this->assertStringNotContainsString('a630fdcd12abcdef', $content->text());
    }

    public function testHtmlInsideJsonStringIsStripped(): void
    {
        $json = json_encode(['html' => '<h1>Geología</h1><p>Rocas y minerales del entorno</p>']);
        $file = $this->tempFile('h.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('Rocas y minerales del entorno', $content->text());
        $this->assertStringNotContainsString('<h1>', $content->text());
    }

    public function testInvalidJsonIsSkippedGracefully(): void
    {
        $file = $this->tempFile('broken.json', '{not valid json,,,');
        $content = (new ContentExtractor())->extract('meta', [['path' => $file]]);
        $this->assertStringContainsString('meta', $content->text());
        $this->assertArrayHasKey('broken.json', $content->skipped());
        $this->assertSame('json_invalid', $content->skipped()['broken.json']);
    }

    public function testJsonNodeBudgetIsCapped(): void
    {
        $values = [];
        for ($i = 0; $i < 2000; $i++) {
            $values[] = 'Frase de contenido educativo número ' . $i . ' sobre la célula.';
        }
        $file = $this->tempFile('big.json', (string) json_encode($values));
        $extractor = new ContentExtractor(['max_json_nodes' => 50]);
        $content = $extractor->extract('', [['path' => $file]]);
        // Las primeras frases (dentro del tope) sí entran...
        $this->assertStringContainsString('número 0', $content->text());
        // ...y las que quedan más allá del tope de nodos, no.
        $this->assertStringNotContainsString('número 1999', $content->text());
    }

    public function testJsonWithOnlyTechnicalNoiseIsSkipped(): void
    {
        $json = json_encode(['id' => 'abc12345', 'src' => 'resources/img.png']);
        $file = $this->tempFile('noise.json', (string) $json);
        $content = (new ContentExtractor())->extract('meta', [['path' => $file]]);
        $this->assertStringContainsString('meta', $content->text());
        $this->assertArrayHasKey('noise.json', $content->skipped());
        $this->assertSame('json_empty', $content->skipped()['noise.json']);
    }

    public function testJsonEntryInsideZipIsExtracted(): void
    {
        $json = json_encode(['lesson' => 'Comparación de los niveles de organización de la materia viva.']);
        $zip = $this->tempZip('scorm.zip', [
            'project.json' => (string) $json,
            'index.html' => '<p>shell</p>',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('niveles de organización de la materia viva', $content->text());
        // sources() registra el fichero externo procesado (el ZIP), no las entradas.
        $this->assertContains('scorm.zip', $content->sources());
    }

    // --- helpers ---

    private function tempFile(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    /** @param array<string,string> $entries name => contents */
    private function tempZip(string $name, array $entries): string
    {
        $path = $this->dir . '/' . $name;
        $za = new \ZipArchive();
        $za->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entryName => $contents) {
            $za->addFromString($entryName, $contents);
        }
        $za->close();
        return $path;
    }

    /** PDF mínimo con un flujo de texto que pdfparser sabe extraer. */
    public static function minimalPdf(string $text): string
    {
        $stream = "BT /F1 24 Tf 100 700 Td (" . $text . ") Tj ET";
        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                . '/Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($objs as $n => $body) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1)
            . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $pdf;
    }
}
