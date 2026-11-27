<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\MediaMetadataReader;
use PHPUnit\Framework\TestCase;

final class MediaMetadataReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->files, 'is_file'));
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oermeta');
        file_put_contents($path, $content);
        return $this->files[] = $path;
    }

    private function elpx(string $contentXml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oerelpx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('content.xml', $contentXml);
        $zip->close();
        return $this->files[] = $path;
    }

    private static function ode(array $properties, bool $namespaced = true): string
    {
        $rows = '';
        foreach ($properties as $key => $value) {
            $rows .= '<odeProperty><key>' . $key . '</key><value>' . htmlspecialchars($value) . '</value></odeProperty>';
        }
        $ns = $namespaced ? ' xmlns="http://www.intef.es/xsd/ode"' : '';
        return '<?xml version="1.0"?><ode' . $ns . '><odeProperties>' . $rows . '</odeProperties><odeNavStructures/></ode>';
    }

    public function testAnElpxGivesTitleDescriptionAuthorAndLicence(): void
    {
        $path = $this->elpx(self::ode([
            'pp_title' => 'Fracciones en la vida cotidiana',
            'pp_description' => '<p>Compras, <b>recetas</b> y deportes.</p>',
            'pp_author' => 'Departamento de Matemáticas',
            'pp_license' => 'creative commons: attribution - share alike 4.0',
        ]));
        $this->assertSame([
            'title' => 'Fracciones en la vida cotidiana',
            'description' => 'Compras, recetas y deportes.',
            'author' => 'Departamento de Matemáticas',
            'licence' => 'creative commons: attribution - share alike 4.0',
        ], (new MediaMetadataReader())->read($path, 'fracciones.elpx'));
    }

    public function testABareOdeRootIsReadToo(): void
    {
        $path = $this->elpx(self::ode(['pp_title' => 'Sin espacio de nombres'], false));
        $this->assertSame(['title' => 'Sin espacio de nombres'], (new MediaMetadataReader())->read($path, 'x.zip'));
    }

    public function testTheDefaultExelearningTitleIsNotATitle(): void
    {
        $path = $this->elpx(self::ode(['pp_title' => 'eXeLearning', 'pp_author' => 'Ana']));
        $this->assertSame(['author' => 'Ana'], (new MediaMetadataReader())->read($path, 'x.elpx'));
    }

    public function testHostileOrBrokenFilesGiveNothing(): void
    {
        $reader = new MediaMetadataReader();
        $entity = '<?xml version="1.0"?><!DOCTYPE ode [<!ENTITY x "boom">]><ode><odeProperties>'
            . '<odeProperty><key>pp_title</key><value>&x;</value></odeProperty></odeProperties></ode>';
        $this->assertSame([], $reader->read($this->elpx($entity), 'x.elpx'));
        $this->assertSame([], $reader->read($this->elpx('not xml at all <'), 'x.elpx'));
        $this->assertSame([], $reader->read($this->file('PK not really a zip'), 'x.elpx'));
        $this->assertSame([], $reader->read('/nonexistent/file.elpx', 'file.elpx'));
        $this->assertSame([], $reader->read($this->file('%PDF-1.4 garbage'), 'x.pdf'));
        $this->assertSame([], $reader->read($this->file('plain'), 'notes.txt'));
    }

    public function testAnOversizedContentXmlIsSkipped(): void
    {
        $reader = new MediaMetadataReader(['max_entry_bytes' => 100]);
        $this->assertSame([], $reader->read($this->elpx(self::ode(['pp_title' => str_repeat('a', 200)])), 'x.elpx'));
    }

    public function testAPdfInfoTitleIsRead(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Title (Fichas de \\(lectura\\)) /Author (X) >> endobj\ntrailer << /Info 1 0 R >>\n%%EOF";
        $this->assertSame(['title' => 'Fichas de (lectura)'], (new MediaMetadataReader())->read($this->file($pdf), 'f.pdf'));
    }

    public function testAPdfUtf16TitleIsDecoded(): void
    {
        $hex = strtoupper(bin2hex("\xFE\xFF" . mb_convert_encoding('Lectura ñ', 'UTF-16BE', 'UTF-8')));
        $pdf = "%PDF-1.7\n1 0 obj << /Title <" . $hex . "> >> endobj\n%%EOF";
        $this->assertSame(['title' => 'Lectura ñ'], (new MediaMetadataReader())->read($this->file($pdf), 'f.pdf'));
    }
}
