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

    /**
     * TASK-060: un PDF de 4 MB lleno de imágenes agotó los 512 MB de PHP dentro
     * de pdfparser (2026-10-08), un error fatal que no se puede capturar y que
     * mataba la propuesta entera. El PDF se lee en un subproceso con su propio
     * límite: si lo agota, ese PDF se salta y el resto del contenido sigue.
     */
    public function testAPdfThatExhaustsTheWorkerMemoryIsSkippedAndExtractionGoesOn(): void
    {
        $pdf = $this->tempFile('pesado.pdf', self::minimalPdf('Saberes basicos mates'));
        $note = $this->tempFile('nota.txt', 'La nota sigue llegando al modelo.');
        $content = (new ContentExtractor(['pdf_worker_memory' => '2M']))
            ->extract('meta', [['path' => $pdf], ['path' => $note]]);

        $this->assertSame('pdf_too_complex', $content->skipped()['pesado.pdf'] ?? null);
        $this->assertStringContainsString('La nota sigue llegando al modelo.', $content->text());
        $this->assertStringNotContainsString('Saberes basicos mates', $content->text());
    }

    /** Sin aislamiento (FPM, `proc_open` deshabilitado) se parsea en proceso, como antes. */
    public function testWithoutIsolationThePdfIsParsedInProcess(): void
    {
        $pdf = $this->tempFile('doc.pdf', self::minimalPdf('Saberes basicos mates'));
        $content = (new ContentExtractor(['pdf_isolation' => false, 'pdf_worker_memory' => '2M']))
            ->extract('', [['path' => $pdf]]);

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

    /**
     * TASK-024(b): en Alpine/musl `iconv` no soporta `//TRANSLIT`, así que
     * smalot/pdfparser pierde el texto de las codificaciones que pasan por ahí
     * —entre ellas WinAnsiEncoding, la más común en PDF— y devuelve vacío.
     * Reportarlo como `pdf_empty` MIENTE: no es que el PDF no tenga texto, es
     * que esta plataforma no sabe leerlo. El motivo debe distinguirlos.
     */
    public function testEmptyPdfOnPlatformWithoutTranslitIsReportedAsIconvUnsupported(): void
    {
        $file = $this->tempFile('sin-texto.pdf', self::minimalPdf(''));
        $extractor = new ContentExtractor(['iconv_translit_supported' => false]);

        $content = $extractor->extract('', [['path' => $file]]);

        $this->assertSame('pdf_iconv_unsupported', $content->skipped()['sin-texto.pdf'] ?? null);
    }

    /** No regresión: en una plataforma sana, un PDF sin texto sigue siendo `pdf_empty`. */
    public function testEmptyPdfOnHealthyPlatformIsStillReportedAsEmpty(): void
    {
        $file = $this->tempFile('sin-texto.pdf', self::minimalPdf(''));
        $extractor = new ContentExtractor(['iconv_translit_supported' => true]);

        $content = $extractor->extract('', [['path' => $file]]);

        $this->assertSame('pdf_empty', $content->skipped()['sin-texto.pdf'] ?? null);
    }

    /**
     * TASK-031: la imagen Debian/glibc que resuelve el PDF (TASK-024b) NO trae
     * la extensión `zip` de PHP, así que `ZipArchive` no existe y el ZIP debe
     * declararse no leíble en esta plataforma, igual que el PDF hace con
     * `pdf_iconv_unsupported`. Inyectable por el mismo motivo que allí: el host
     * SÍ tiene la extensión y nunca podría probar la rama rota.
     */
    public function testZipOnPlatformWithoutZipArchiveIsReportedAsUnsupported(): void
    {
        $zip = $this->tempZip('package.zip', ['index.html' => '<p>Hola SCORM</p>']);
        $extractor = new ContentExtractor(['zip_supported' => false]);

        $content = $extractor->extract('', [['path' => $zip]]);

        $this->assertSame('zip_unsupported', $content->skipped()['package.zip'] ?? null);
    }

    /**
     * El defecto de fondo de TASK-031: sin `ZipArchive` el `new` lanzaba un
     * `Error` no capturado que tumbaba el propose ENTERO, así que un item con un
     * SCORM y un PDF perdía también el PDF. Debe seguir con el resto de medios.
     */
    public function testOtherMediaAreStillExtractedWhenZipArchiveIsMissing(): void
    {
        $zip = $this->tempZip('package.zip', ['index.html' => '<p>Hola SCORM</p>']);
        $txt = $this->tempFile('apuntes.txt', 'El aparato circulatorio transporta la sangre.');
        $extractor = new ContentExtractor(['zip_supported' => false]);

        $content = $extractor->extract('', [['path' => $zip], ['path' => $txt]]);

        $this->assertStringContainsString('aparato circulatorio', $content->text());
        $this->assertSame('zip_unsupported', $content->skipped()['package.zip'] ?? null);
    }

    /** No regresión: donde la extensión está, el ZIP se sigue leyendo igual. */
    public function testZipIsStillExtractedOnPlatformWithZipArchive(): void
    {
        $zip = $this->tempZip('package.zip', ['index.html' => '<p>Hola SCORM</p>']);
        $extractor = new ContentExtractor(['zip_supported' => true]);

        $content = $extractor->extract('', [['path' => $zip]]);

        $this->assertStringContainsString('Hola SCORM', $content->text());
        $this->assertSame([], $content->skipped());
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

    // --- Endurecimiento TASK-022 (diagnóstico con REAs reales, 2026-07-07) ---

    public function testVendorNoisePathEntriesInZipAreSkipped(): void
    {
        // Caso #37129: los ZIP de herramientas de autor arrastran vendor completo
        // (ckeditor samples, licencias, plugins) que entierra el contenido real.
        $zip = $this->tempZip('scorm.zip', [
            'ckeditor/samples/old/datafiltering.html' => '<p>Apollo 11 was the spaceflight sample</p>',
            'pkg/plugins/wiris/readme.txt' => 'WIRIS plugin licensing boilerplate text',
            'contenido/leccion.html' => '<p>Los porcentajes en la vida cotidiana</p>',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('porcentajes en la vida cotidiana', $content->text());
        $this->assertStringNotContainsString('Apollo 11', $content->text());
        $this->assertStringNotContainsString('WIRIS', $content->text());
        $this->assertSame('noise_path', $content->skipped()['ckeditor/samples/old/datafiltering.html']);
        $this->assertSame('noise_path', $content->skipped()['pkg/plugins/wiris/readme.txt']);
    }

    public function testNoiseSegmentMatchesDirectoriesNotFilenames(): void
    {
        // "fonts.html" es un FICHERO, no la carpeta fonts/: no debe denegarse.
        $zip = $this->tempZip('p.zip', [
            'fonts.html' => '<p>La tipografía en el arte contemporáneo</p>',
            'fonts/license.txt' => 'Font license boilerplate to be ignored',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('tipografía en el arte contemporáneo', $content->text());
        $this->assertStringNotContainsString('boilerplate', $content->text());
    }

    public function testNoiseEntriesDoNotConsumeEntryQuota(): void
    {
        // Caso #37129: ~900 entradas ckeditor quemaban max_zip_entries y el
        // contenido real del final del ZIP ni se llegaba a leer.
        $entries = [];
        for ($i = 0; $i < 20; $i++) {
            $entries["ckeditor/junk$i.html"] = '<p>editor sample</p>';
        }
        $entries['zz_contenido.txt'] = 'El contenido real del recurso educativo llega al final.';
        $extractor = new ContentExtractor(['max_zip_entries' => 10]);
        $zip = $this->tempZip('big.zip', $entries);
        $content = $extractor->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('llega al final', $content->text());
    }

    public function testBudgetIsSharedFairlyAcrossPieces(): void
    {
        // Reparto equitativo: una pieza enorme (relleno) no expulsa la señal de
        // las demás cuando el total excede el presupuesto (caso #37129). El
        // relleno es incompresible para no disparar la defensa anti zip-bomb.
        $zip = $this->tempZip('mix.zip', [
            'aaa_relleno.txt' => bin2hex(random_bytes(12000)),
            'zzz_senal.txt' => 'La fotosíntesis transforma la energía luminosa en energía química.',
        ]);
        $extractor = new ContentExtractor(['max_total_chars' => 2000]);
        $content = $extractor->extract('', [['path' => $zip]]);
        $this->assertTrue($content->isTruncated());
        $this->assertStringContainsString('fotosíntesis', $content->text());
        $this->assertLessThanOrEqual(2000, mb_strlen($content->text()));
    }

    public function testSingleSourceStillUsesFullBudget(): void
    {
        // Con una sola pieza no hay reparto: dispone del presupuesto completo
        // (caso #40437, un único PDF bueno — un tope fijo por pieza lo mutilaría).
        $file = $this->tempFile('guia.txt', str_repeat('palabra ', 1000));
        $extractor = new ContentExtractor(['max_total_chars' => 5000]);
        $content = $extractor->extract('', [['path' => $file]]);
        $this->assertTrue($content->isTruncated());
        $this->assertGreaterThan(4500, mb_strlen($content->text()));
    }

    public function testAuthoringToolIdentifiersInJsonAreFiltered(): void
    {
        // Caso #3181/#4359 (Netex): ids de interfaz que pasaban el filtro por longitud.
        $json = json_encode([
            'a' => 'navigationSectionInteracted',
            'b' => 'imagelink_e7af877d2f1d45c1b82f1053778191fe',
            'c' => 'interface_view_581-001_look_001',
            'd' => 'Identificación de los orgánulos de la célula eucariota.',
            'e' => 'ntx-text-font-style-normal-extra-largo',
        ]);
        $file = $this->tempFile('netex.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('orgánulos de la célula eucariota', $content->text());
        $this->assertStringNotContainsString('navigationSectionInteracted', $content->text());
        $this->assertStringNotContainsString('imagelink_', $content->text());
        $this->assertStringNotContainsString('interface_view_581', $content->text());
        $this->assertStringNotContainsString('ntx-text-font-style', $content->text());
    }

    public function testCssRuleStringsInJsonAreFiltered(): void
    {
        // Caso #37129: CSS embebido como string JSON (multi-palabra, pasaba el filtro).
        $json = json_encode([
            'css' => '.Wirisformula:not([width]) { vertical-align: middle !important; }',
            'body' => 'El porcentaje expresa una proporción sobre cien unidades.',
        ]);
        $file = $this->tempFile('estilos.json', (string) $json);
        $content = (new ContentExtractor())->extract('', [['path' => $file]]);
        $this->assertStringContainsString('proporción sobre cien unidades', $content->text());
        $this->assertStringNotContainsString('Wirisformula', $content->text());
        $this->assertStringNotContainsString('!important', $content->text());
    }

    // --- Paquetes eXeLearning (TASK-053, issue #59) ---

    public function testElpxContentXmlIsExtractedWithoutRenderedDuplicate(): void
    {
        $elpx = $this->tempZip('curso.elpx', [
            'content.xml' => self::odeXml(
                [
                    'pp_title' => 'La fotosíntesis en las plantas',
                    'PP_DESCRIPTION' => 'Unidad sobre la nutrición vegetal para Biología.',
                    'pp_lang' => 'es',
                    'pp_extraHeadContent' => '<meta name="generator" content="eXeLearning">',
                ],
                [self::odePage('p1', '', 'Introducción', [
                    self::odeComponent('<p>Las plantas fabrican su alimento con la luz.</p>'),
                ])]
            ),
            'index.html' => '<p>RENDERED_DUPLICATE las plantas fabrican</p>',
            'html/introduccion.html' => '<p>RENDERED_DUPLICATE otra página</p>',
            'theme/config.xml' => '<theme><name>THEME_NOISE base theme</name></theme>',
            'idevices/text/text.html' => '<p>IDEVICE_TEMPLATE_NOISE plantilla</p>',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $elpx]]);
        $text = $content->text();
        $this->assertStringContainsString('La fotosíntesis en las plantas', $text);
        $this->assertStringContainsString('nutrición vegetal para Biología', $text);
        $this->assertStringContainsString('Introducción', $text);
        $this->assertStringContainsString('Las plantas fabrican su alimento con la luz.', $text);
        $this->assertStringNotContainsString('RENDERED_DUPLICATE', $text);
        $this->assertStringNotContainsString('THEME_NOISE', $text);
        $this->assertStringNotContainsString('IDEVICE_TEMPLATE_NOISE', $text);
        $this->assertStringNotContainsString('generator', $text);
        $this->assertStringNotContainsString('CDATA', $text);
        $this->assertContains('curso.elpx', $content->sources());
        $this->assertArrayNotHasKey('curso.elpx', $content->skipped());
    }

    public function testElpxGameDataInEveryEncodingAndVideoJsonAreDecoded(): void
    {
        $plain = json_encode(['questionsGame' => [['quextion' => '¿Qué orgánulo realiza la fotosíntesis?']]]);
        $uri = rawurlencode((string) json_encode(['wordsGame' => [['definition' => 'Pigmento verde de las hojas']]]));
        $xor = self::exeXorEscape((string) json_encode(
            ['questionsGame' => [['quextion' => '¿Dónde ocurre la fase luminosa?']]],
            JSON_UNESCAPED_UNICODE
        ));
        $video = json_encode(['slides' => [['type' => 'text', 'text' => '<p>¿Por qué es importante el suelo?</p>']]]);
        $elpx = $this->tempZip('juegos.elpx', ['content.xml' => self::odeXml([], [
            self::odePage('p1', '', 'Juegos', [
                self::odeComponent('<div class="trivial-IDevice"><div class="trivial-DataGame js-hidden">'
                    . $plain . '</div></div>'),
                self::odeComponent('<div class="sopa-DataGame js-hidden">' . $uri . '</div>'),
                self::odeComponent('<div class="quext-IDevice"><div class="quext-version js-hidden">2</div>'
                    . '<div class="quext-DataGame js-hidden">' . $xor . '</div></div>'),
                self::odeComponent('<div class="exe-interactive-video"><script id="exe-interactive-video-contents"'
                    . ' type="application/json">' . $video . '</script></div>'),
            ]),
        ])]);
        $text = (new ContentExtractor())->extract('', [['path' => $elpx]])->text();
        $this->assertStringContainsString('¿Qué orgánulo realiza la fotosíntesis?', $text);
        $this->assertStringContainsString('Pigmento verde de las hojas', $text);
        $this->assertStringContainsString('¿Dónde ocurre la fase luminosa?', $text);
        $this->assertStringContainsString('¿Por qué es importante el suelo?', $text);
        $this->assertStringNotContainsString('%7B', $text);
        $this->assertStringNotContainsString('%u', $text);
        $this->assertStringNotContainsString('questionsGame', $text);
    }

    public function testElpxSkipsHiddenContentButKeepsTeacherOnly(): void
    {
        $elpx = $this->tempZip('flags.elpx', ['content.xml' => self::odeXml([], [
            self::odePage('p1', '', 'Página visible', [
                self::odeComponent('<p>Contenido visible para el alumnado.</p>'),
                self::odeComponent('<p>HIDDEN_IDEVICE borrador oculto.</p>', '', ['visibility' => 'false']),
                self::odeComponent('<p>Solución comentada para el docente.</p>', '', ['teacherOnly' => 'true']),
            ]),
            self::odePage('p2', '', 'HIDDEN_PAGE título', [
                self::odeComponent('<p>HIDDEN_PAGE_TEXT texto de página oculta.</p>'),
            ], ['visibility' => 'false']),
            self::odePage('p3', 'p2', 'HIDDEN_CHILD subpágina', [
                self::odeComponent('<p>HIDDEN_CHILD_TEXT hija de página oculta.</p>'),
            ]),
            self::odePage('p4', '', 'Bloque oculto', [
                self::odeComponent('<p>HIDDEN_BLOCK texto del bloque oculto.</p>'),
            ], [], ['visibility' => 'false']),
        ])]);
        $text = (new ContentExtractor())->extract('', [['path' => $elpx]])->text();
        $this->assertStringContainsString('Contenido visible para el alumnado.', $text);
        $this->assertStringContainsString('Solución comentada para el docente.', $text);
        $this->assertStringNotContainsString('HIDDEN_', $text);
    }

    public function testElpxPagesFollowTheTreeOrderAndRepeatedTextIsKeptOnce(): void
    {
        $elpx = $this->tempZip('orden.elpx', ['content.xml' => self::odeXml([], [
            self::odePage('b', '', 'Segunda página', [self::odeComponent('<p>Texto repetido en dos sitios.</p>')], [], [], 1),
            self::odePage('a', '', 'Primera página', [self::odeComponent('<p>Texto repetido en dos sitios.</p>')], [], [], 0),
            self::odePage('a1', 'a', 'Subpágina de la primera', [], [], [], 0),
        ])]);
        $text = (new ContentExtractor())->extract('', [['path' => $elpx]])->text();
        $first = strpos($text, 'Primera página');
        $child = strpos($text, 'Subpágina de la primera');
        $second = strpos($text, 'Segunda página');
        $this->assertNotFalse($first);
        $this->assertTrue($first < $child && $child < $second, $text);
        $this->assertSame(1, substr_count($text, 'Texto repetido en dos sitios.'));
    }

    public function testElpxJsonPropertiesAreUsedOnlyWhenHtmlViewIsEmpty(): void
    {
        $json = (string) json_encode([
            'ideviceId' => '20251217061742582ZHV',
            'textTextarea' => '<p>Texto guardado solo en el estado del editor.</p>',
        ]);
        $dup = (string) json_encode(['textTextarea' => '<p>JSON_DUPLICATE del htmlView.</p>']);
        $elpx = $this->tempZip('json.elpx', ['content.xml' => self::odeXml([], [
            self::odePage('p1', '', 'Página', [
                self::odeComponent('', $json),
                self::odeComponent('<p>Texto del htmlView.</p>', $dup),
            ]),
        ])]);
        $text = (new ContentExtractor())->extract('', [['path' => $elpx]])->text();
        $this->assertStringContainsString('Texto guardado solo en el estado del editor.', $text);
        $this->assertStringContainsString('Texto del htmlView.', $text);
        $this->assertStringNotContainsString('JSON_DUPLICATE', $text);
        $this->assertStringNotContainsString('20251217061742582ZHV', $text);
    }

    public function testZipOrScormWithOdeContentXmlTakesTheStructuredPath(): void
    {
        $scorm = $this->tempZip('export_scorm.zip', [
            'imsmanifest.xml' => '<manifest><title>MANIFEST_TEXT manifiesto</title></manifest>',
            'index.html' => '<p>RENDERED_DUPLICATE página</p>',
            'content.xml' => self::odeXml(['pp_title' => 'Un héroe medieval'], [
                self::odePage('p1', '', 'El Cid', [self::odeComponent('<p>Rodrigo Díaz de Vivar fue un caballero.</p>')]),
            ]),
            'original.elpx' => 'nested package bytes',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $scorm]]);
        $this->assertStringContainsString('Rodrigo Díaz de Vivar fue un caballero.', $content->text());
        $this->assertStringContainsString('Un héroe medieval', $content->text());
        $this->assertStringNotContainsString('RENDERED_DUPLICATE', $content->text());
        $this->assertStringNotContainsString('MANIFEST_TEXT', $content->text());
    }

    public function testExportWithBareOdeRootTakesTheStructuredPath(): void
    {
        // Las exportaciones reales (web, SCORM, IMS e incluso .elpx exportado)
        // escriben `<ode>` sin espacio de nombres; solo el guardado del editor lo
        // declara (comprobado en test/fixtures/export de eXeLearning, 2026-10-05).
        $xml = str_replace(
            '<ode xmlns="http://www.intef.es/xsd/ode" version="2.0">',
            '<ode>',
            self::odeXml(['pp_title' => 'Un héroe medieval'], [
                self::odePage('p1', '', 'El Cid', [self::odeComponent('<p>Rodrigo Díaz de Vivar fue un caballero.</p>')]),
            ])
        );
        $scorm = $this->tempZip('cid_scorm.zip', [
            'content.xml' => $xml,
            'html/el-cid.html' => '<p>RENDERED_DUPLICATE página</p>',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $scorm]]);
        $this->assertStringContainsString('Título: Un héroe medieval', $content->text());
        $this->assertStringContainsString('Rodrigo Díaz de Vivar fue un caballero.', $content->text());
        $this->assertStringNotContainsString('RENDERED_DUPLICATE', $content->text());
    }

    public function testBareOdeRootWithoutOdeStructureKeepsTheGenericPath(): void
    {
        $zip = $this->tempZip('otro.zip', [
            'content.xml' => '<ode><nota>Un documento llamado ode que no es de eXeLearning.</nota></ode>',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('Un documento llamado ode que no es de eXeLearning.', $content->text());
    }

    public function testZipWithForeignContentXmlKeepsTheGenericPath(): void
    {
        $zip = $this->tempZip('otro.zip', [
            'content.xml' => '<doc><p>Un content.xml cualquiera sin espacio ODE.</p></doc>',
            'leccion.html' => '<p>La lección se sigue leyendo como hoy.</p>',
            'pkg/original.elpx' => 'nested package bytes',
        ]);
        $content = (new ContentExtractor())->extract('', [['path' => $zip]]);
        $this->assertStringContainsString('Un content.xml cualquiera sin espacio ODE.', $content->text());
        $this->assertStringContainsString('La lección se sigue leyendo como hoy.', $content->text());
        $this->assertSame('nested_zip', $content->skipped()['pkg/original.elpx']);
        $this->assertArrayNotHasKey('otro.zip', $content->skipped());
    }

    public function testElpxWithoutContentXmlFallsBackToRenderedPagesWithoutNoise(): void
    {
        $entries = [];
        for ($i = 0; $i < 20; $i++) {
            $entries["theme/icons/icon$i.svg"] = '<svg/>';
            $entries["idevices/text/tpl$i.html"] = '<p>IDEVICE_TEMPLATE_NOISE plantilla</p>';
            $entries["content/css/c$i.css"] = '.x{}';
        }
        $entries['theme/config.xml'] = '<theme>THEME_NOISE</theme>';
        $entries['index.html'] = '<p>Portada del recurso sobre los volcanes.</p>';
        $entries['html/tipos.html'] = '<p>Tipos de erupciones volcánicas.</p>';
        $elpx = $this->tempZip('sin-xml.elpx', $entries);
        $content = (new ContentExtractor(['max_zip_entries' => 5]))->extract('', [['path' => $elpx]]);
        $this->assertStringContainsString('Portada del recurso sobre los volcanes.', $content->text());
        $this->assertStringContainsString('Tipos de erupciones volcánicas.', $content->text());
        $this->assertStringNotContainsString('NOISE', $content->text());
        $this->assertSame('elpx_content_missing', $content->skipped()['sin-xml.elpx']);
    }

    public function testInvalidOrNonOdeContentXmlInElpxIsReportedAndFallsBack(): void
    {
        $broken = $this->tempZip('roto.elpx', [
            'content.xml' => '<ode xmlns="http://www.intef.es/xsd/ode"><odeProperties>',
            'index.html' => '<p>Página renderizada de respaldo.</p>',
        ]);
        $foreign = $this->tempZip('ajeno.elpx', ['content.xml' => '<doc>Otro formato</doc>']);
        $content = (new ContentExtractor())->extract('', [['path' => $broken], ['path' => $foreign]]);
        $this->assertStringContainsString('Página renderizada de respaldo.', $content->text());
        $this->assertSame('elpx_content_invalid', $content->skipped()['roto.elpx']);
        $this->assertSame('elpx_content_invalid', $content->skipped()['ajeno.elpx']);
    }

    public function testCorruptElpxIsSkippedWithoutFailingTheOtherMedia(): void
    {
        $elpx = $this->tempFile('corrupto.elpx', 'this is not a zip archive');
        $txt = $this->tempFile('nota.txt', 'La nota acompañante sigue llegando.');
        $content = (new ContentExtractor())->extract('meta', [['path' => $elpx], ['path' => $txt]]);
        $this->assertSame('zip_unreadable', $content->skipped()['corrupto.elpx']);
        $this->assertStringContainsString('La nota acompañante sigue llegando.', $content->text());
    }

    public function testElpxOnPlatformWithoutZipArchiveIsReportedAsUnsupported(): void
    {
        $elpx = $this->tempZip('curso.elpx', ['content.xml' => self::odeXml([], [])]);
        $content = (new ContentExtractor(['zip_supported' => false]))->extract('', [['path' => $elpx]]);
        $this->assertSame('zip_unsupported', $content->skipped()['curso.elpx']);
    }

    public function testElpxContentXmlDeclaringEntitiesIsRejected(): void
    {
        $secret = $this->tempFile('secret.txt', 'TOP_SECRET_HOST_FILE');
        $xml = '<?xml version="1.0"?><!DOCTYPE ode [<!ENTITY ext SYSTEM "file://' . $secret . '">'
            . '<!ENTITY lol "LOL_EXPANDED">]>'
            . '<ode xmlns="http://www.intef.es/xsd/ode"><odeProperties><odeProperty><key>pp_title</key>'
            . '<value>&ext; &lol;</value></odeProperty></odeProperties></ode>';
        $elpx = $this->tempZip('xxe.elpx', ['content.xml' => $xml]);
        $content = (new ContentExtractor())->extract('', [['path' => $elpx]]);
        $this->assertStringNotContainsString('TOP_SECRET_HOST_FILE', $content->text());
        $this->assertStringNotContainsString('LOL_EXPANDED', $content->text());
        $this->assertSame('elpx_content_invalid', $content->skipped()['xxe.elpx']);
    }

    public function testElpxContentXmlObeysTheEntrySizeCap(): void
    {
        $elpx = $this->tempZip('enorme.elpx', ['content.xml' => self::odeXml([], [
            self::odePage('p1', '', 'Página', [self::odeComponent('<p>' . str_repeat('Texto largo ', 200) . '</p>')]),
        ])]);
        $content = (new ContentExtractor(['max_entry_bytes' => 512]))->extract('', [['path' => $elpx]]);
        $this->assertSame('entry_too_large', $content->skipped()['content.xml']);
        $this->assertStringNotContainsString('Texto largo', $content->text());
    }

    public function testElpxResourcesWithTextAreReadAndOtherAssetsIgnored(): void
    {
        $entries = ['content.xml' => self::odeXml([], [
            self::odePage('p1', '', 'Página', [self::odeComponent('<p>Texto de la página.</p>')]),
        ])];
        for ($i = 0; $i < 10; $i++) {
            $entries["libs/vendor$i.js"] = 'vendor';
            $entries["content/img/logo$i.png"] = 'png';
            // Imágenes del autor (el Manual real lleva ~340): sin texto que leer,
            // no deben agotar la cuota antes de llegar a la guía.
            $entries["content/resources/img$i.png"] = 'png';
        }
        $entries['content/resources/guia.txt'] = 'Guía didáctica adjunta al paquete.';
        $entries['content/resources/foto.jpg'] = 'jpg bytes';
        $elpx = $this->tempZip('recursos.elpx', $entries);
        $content = (new ContentExtractor(['max_zip_entries' => 3]))->extract('', [['path' => $elpx]]);
        $this->assertStringContainsString('Guía didáctica adjunta al paquete.', $content->text());
        $this->assertStringContainsString('Texto de la página.', $content->text());
        $this->assertSame('unsupported:jpg', $content->skipped()['content/resources/foto.jpg']);
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

    /**
     * `content.xml` mínimo de eXeLearning (ODE 2.0) con htmlView/jsonProperties
     * en CDATA, como en las exportaciones reales.
     *
     * @param array<string,string> $properties
     * @param string[] $pages
     */
    private static function odeXml(array $properties, array $pages): string
    {
        $props = '';
        foreach ($properties as $key => $value) {
            $props .= '<odeProperty><key>' . $key . '</key><value>' . htmlspecialchars($value) . '</value></odeProperty>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE ode SYSTEM "content.dtd">' . "\n"
            . '<ode xmlns="http://www.intef.es/xsd/ode" version="2.0">'
            . '<odeProperties>' . $props . '</odeProperties>'
            . '<odeNavStructures>' . implode('', $pages) . '</odeNavStructures></ode>';
    }

    /**
     * @param string[] $components
     * @param array<string,string> $pageProps
     * @param array<string,string> $blockProps
     */
    private static function odePage(
        string $id,
        string $parent,
        string $name,
        array $components,
        array $pageProps = [],
        array $blockProps = [],
        int $order = 0
    ): string {
        return '<odeNavStructure><odePageId>' . $id . '</odePageId><odeParentPageId>' . $parent
            . '</odeParentPageId><pageName>' . htmlspecialchars($name) . '</pageName>'
            . '<odeNavStructureOrder>' . $order . '</odeNavStructureOrder>'
            . self::odeProps('odeNavStructure', $pageProps)
            . '<odePagStructures><odePagStructure><odeBlockId>b-' . $id . '</odeBlockId><blockName></blockName>'
            . '<odePagStructureOrder>1</odePagStructureOrder>' . self::odeProps('odePagStructure', $blockProps)
            . '<odeComponents>' . implode('', $components) . '</odeComponents>'
            . '</odePagStructure></odePagStructures></odeNavStructure>';
    }

    /** @param array<string,string> $props */
    private static function odeComponent(string $html, string $json = '', array $props = []): string
    {
        return '<odeComponent><odeIdeviceTypeName>text</odeIdeviceTypeName>'
            . '<htmlView><![CDATA[' . $html . ']]></htmlView>'
            . '<jsonProperties><![CDATA[' . $json . ']]></jsonProperties>'
            . '<odeComponentsOrder>1</odeComponentsOrder>' . self::odeProps('odeComponents', $props)
            . '</odeComponent>';
    }

    /** @param array<string,string> $props */
    private static function odeProps(string $prefix, array $props): string
    {
        $out = '';
        foreach ($props + ['visibility' => 'true', 'teacherOnly' => 'false'] as $key => $value) {
            $out .= '<' . $prefix . 'Property><key>' . $key . '</key><value>' . $value . '</value></'
                . $prefix . 'Property>';
        }
        return '<' . $prefix . 'Properties>' . $out . '</' . $prefix . 'Properties>';
    }

    /**
     * Codificación ofuscada de los juegos de eXeLearning: cada unidad UTF-16
     * XOR 146 y después `escape()` de JavaScript (%XX / %uXXXX).
     */
    private static function exeXorEscape(string $json): string
    {
        $out = '';
        foreach (mb_str_split($json) as $char) {
            $code = mb_ord($char) ^ 146;
            $plain = mb_chr($code);
            if (1 === preg_match('#^[A-Za-z0-9@*_+./-]$#', $plain)) {
                $out .= $plain;
            } elseif ($code < 256) {
                $out .= sprintf('%%%02X', $code);
            } else {
                $out .= sprintf('%%u%04X', $code);
            }
        }
        return $out;
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
