<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\ElpxDeclaredAlignment;
use PHPUnit\Framework\TestCase;

/**
 * TASK-057 / RF-020: the curricular alignment an eXeLearning package declares in
 * its `lomloe` iDevice. Only basic knowledge and criteria count (owner decision,
 * 2026-10-06); specific competences are ignored.
 */
final class ElpxDeclaredAlignmentTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testReadsKnowledgeCriteriaCoursesAndSubjects(): void
    {
        $declared = (new ElpxDeclaredAlignment())->fromContentXml($this->contentXml($this->lomloe([
            $this->saber('4º Primaria', 'C9N', 'Conocimiento del Medio', 'PC9N04SBIII.2.2'),
            $this->saber('4º Primaria', 'EAR', 'Educación Artística', 'PEAR04SBIV.1'),
            $this->criterio('4º Primaria', 'C9N', 'Conocimiento del Medio', 'PC9N04CE5.3', 'PC9NC5'),
        ])));

        self::assertNotNull($declared);
        self::assertSame('Educación Primaria', $declared['stage']);
        self::assertSame(['4º Primaria'], $declared['courses']);
        self::assertSame([
            ['course' => '4º Primaria', 'name' => 'Conocimiento del Medio'],
            ['course' => '4º Primaria', 'name' => 'Educación Artística'],
        ], $declared['subjects']);
        self::assertSame(['PC9N04SBIII.2.2', 'PEAR04SBIV.1'], $declared['knowledge']);
        // The competence code PC9NC5 is not a criterion and stays out.
        self::assertSame(['PC9N04CE5.3'], $declared['criteria']);
    }

    public function testNamespacedRootIsReadToo(): void
    {
        $xml = $this->contentXml($this->lomloe([$this->saber('4º ESO', 'TEE', 'Tecnología', 'STEE04SBIII.1')]), true);

        self::assertSame(['STEE04SBIII.1'], (new ElpxDeclaredAlignment())->fromContentXml($xml)['knowledge'] ?? null);
    }

    public function testTheSelectedCourseCountsWhenNoSelectionNamesOne(): void
    {
        $declared = (new ElpxDeclaredAlignment())->fromContentXml($this->contentXml($this->lomloe([], '4º ESO')));

        self::assertSame(['4º ESO'], $declared['courses'] ?? null);
        self::assertSame([], $declared['knowledge']);
    }

    public function testPackagesWithoutDeclarationOrUnreadableDataGiveNull(): void
    {
        $reader = new ElpxDeclaredAlignment();

        self::assertNull($reader->fromContentXml($this->contentXml('')));
        self::assertNull($reader->fromContentXml($this->contentXml($this->component('lomloe', '{not json'))));
        self::assertNull($reader->fromContentXml('not xml at all'));
        self::assertNull($reader->fromContentXml(''));
    }

    public function testEntityDeclarationsAreRejected(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE ode [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            . '<ode><odeNavStructures>' . $this->lomloe([$this->saber('4º ESO', 'TEE', 'T', '&x;')])
            . '</odeNavStructures></ode>';

        self::assertNull((new ElpxDeclaredAlignment())->fromContentXml($xml));
    }

    public function testReadsThePackageFromDisk(): void
    {
        $path = $this->zip(['content.xml' => $this->contentXml($this->lomloe([
            $this->saber('6º Infantil de 5 años', 'DE3', 'Descubrimiento', 'IDE306SBI.3'),
        ]))]);

        self::assertSame(['IDE306SBI.3'], (new ElpxDeclaredAlignment())->fromFile($path)['knowledge'] ?? null);
    }

    public function testPackagesWithoutContentXmlOrNotZipGiveNull(): void
    {
        $reader = new ElpxDeclaredAlignment();

        self::assertNull($reader->fromFile($this->zip(['index.html' => '<p>hola</p>'])));
        $notZip = (string) tempnam(sys_get_temp_dir(), 'oer-elpx-');
        $this->files[] = $notZip;
        file_put_contents($notZip, 'plain text');
        self::assertNull($reader->fromFile($notZip));
        self::assertNull($reader->fromFile('/does/not/exist.elpx'));
    }

    public function testAnOversizedContentXmlIsNotRead(): void
    {
        $path = $this->zip(['content.xml' => $this->contentXml($this->lomloe([
            $this->saber('4º ESO', 'TEE', 'Tecnología', 'STEE04SBIII.1'),
        ]))]);

        self::assertNull((new ElpxDeclaredAlignment(100))->fromFile($path));
    }

    /** @param array<int,array<string,mixed>> $selections */
    private function lomloe(array $selections, string $selectedCourse = '4º Primaria'): string
    {
        return $this->component('lomloe', (string) json_encode([
            'lomloeSelectedEtapa' => 'Educación Primaria',
            'lomloeSelectedNivel' => $selectedCourse,
            'lomloeSelections' => $selections,
        ]));
    }

    private function component(string $type, string $json): string
    {
        return '<odeNavStructure><odePageId>p1</odePageId><odePagStructures><odePagStructure><odeComponents>'
            . '<odeComponent><odeIdeviceTypeName>' . $type . '</odeIdeviceTypeName>'
            . '<htmlView><![CDATA[<p>table</p>]]></htmlView>'
            . '<jsonProperties><![CDATA[' . $json . ']]></jsonProperties>'
            . '</odeComponent></odeComponents></odePagStructure></odePagStructures></odeNavStructure>';
    }

    private function contentXml(string $structures, bool $namespaced = false): string
    {
        $ns = $namespaced ? ' xmlns="http://www.intef.es/xsd/ode"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?><ode' . $ns . ' version="2.0"><odeNavStructures>'
            . $structures . '</odeNavStructures></ode>';
    }

    /** @return array<string,string> */
    private function saber(string $course, string $area, string $name, string $code): array
    {
        return [
            'type' => 'saber', 'nivel' => $course, 'codArea' => $area, 'denominacion' => $name, 'nombre' => $code,
        ];
    }

    /** @return array<string,string> */
    private function criterio(string $course, string $area, string $name, string $code, string $competence): array
    {
        return [
            'type' => 'criterio', 'nivel' => $course, 'codArea' => $area, 'denominacion' => $name,
            'codigoCriterio' => $code, 'codigoComp' => $competence,
        ];
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oer-elpx-') . '.elpx';
        $this->files[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
