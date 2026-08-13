<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurricularGrouping;
use PHPUnit\Framework\TestCase;

/**
 * Agrupamiento curricular del panel de detalle (TASK-032).
 *
 * El drawer mostraba listas planas por dimensión: con dos cursos mezclados, el
 * curador no podía saber qué saber pertenece a cuál. Medido en el catálogo
 * real: el REA #40437 lista «Educación Física» CUATRO veces seguidas.
 */
final class CurricularGroupingTest extends TestCase
{
    /** @return array{id:int,title:string,courseId:?int} */
    private function value(int $id, string $title, ?int $courseId): array
    {
        return ['id' => $id, 'title' => $title, 'courseId' => $courseId];
    }

    public function testNothingInNothingOut(): void
    {
        $result = CurricularGrouping::build([]);

        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['axes']);
        $this->assertSame([], $result['orphans']);
    }

    public function testACourseWithItsSubjectFormsAGroup(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología y Geología', 1)],
            'lrmi:teaches' => [$this->value(20, 'B3.1', 1)],
            'lrmi:assesses' => [$this->value(30, 'CE2.1', 1)],
        ]);

        $this->assertCount(1, $result['groups']);
        $this->assertSame(1, $result['groups'][0]['courseId']);
        $this->assertSame('3º ESO', $result['groups'][0]['courseTitle']);
        $this->assertSame(['Biología y Geología'], $result['groups'][0]['subjects']);
        $this->assertSame(['B3.1'], $result['groups'][0]['teaches']);
        $this->assertSame(['CE2.1'], $result['groups'][0]['assesses']);
        $this->assertSame([], $result['orphans']);
    }

    /** El defecto que motiva la tarea: dos cursos mezclados en listas planas. */
    public function testTwoCoursesKeepTheirOwnValues(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1), $this->value(2, '1º Bach', 2)],
            'schema:about' => [$this->value(10, 'Biología', 1), $this->value(11, 'Matemáticas I', 2)],
            'lrmi:teaches' => [$this->value(20, 'B3.1', 1), $this->value(21, 'SBII.2.3', 2)],
        ]);

        $this->assertCount(2, $result['groups']);
        $this->assertSame(['B3.1'], $result['groups'][0]['teaches']);
        $this->assertSame(['SBII.2.3'], $result['groups'][1]['teaches']);
        $this->assertSame(['Matemáticas I'], $result['groups'][1]['subjects']);
    }

    public function testGroupOrderFollowsTheDeclaredCourses(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(2, '1º Bach', 2), $this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1), $this->value(11, 'Matemáticas I', 2)],
        ]);

        $this->assertSame(['1º Bach', '3º ESO'], array_column($result['groups'], 'courseTitle'));
    }

    /** El caso real de los 7 REA (ADR-0016). */
    public function testACourseWithoutSubjectIsAnOrphanAndFormsNoGroup(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1), $this->value(9, '4º ESO', 9)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
        ]);

        $this->assertSame(['3º ESO'], array_column($result['groups'], 'courseTitle'));
        $this->assertSame(
            [['term' => 'lrmi:educationalLevel', 'title' => '4º ESO', 'reason' => 'course-without-subject']],
            $result['orphans']
        );
    }

    public function testAValueWhoseCourseIsNotDeclaredIsAnOrphan(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
            'lrmi:teaches' => [$this->value(20, 'Suelto', 77)],
        ]);

        $this->assertSame([], $result['groups'][0]['teaches']);
        $this->assertSame(
            [['term' => 'lrmi:teaches', 'title' => 'Suelto', 'reason' => 'course-not-declared']],
            $result['orphans']
        );
    }

    public function testAValueWithoutResolvedCourseIsAnOrphan(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
            'lrmi:assesses' => [$this->value(30, 'Sin curso', null)],
        ]);

        $this->assertSame(
            [['term' => 'lrmi:assesses', 'title' => 'Sin curso', 'reason' => 'course-not-declared']],
            $result['orphans']
        );
    }

    /** Los saberes de un curso huérfano no desaparecen: caen a huérfanos. */
    public function testValuesOfAnUnsupportedCourseFallToOrphans(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(9, '4º ESO', 9)],
            'lrmi:teaches' => [$this->value(20, 'B4.1', 9)],
        ]);

        $this->assertSame([], $result['groups']);
        $this->assertSame(
            ['4º ESO', 'B4.1'],
            array_column($result['orphans'], 'title')
        );
    }

    public function testAxesAreNeverGrouped(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
            'dcterms:relation' => [$this->value(40, 'Patrimonio', null), $this->value(41, 'Sostenibilidad', null)],
        ]);

        $this->assertSame(['Patrimonio', 'Sostenibilidad'], $result['axes']);
        $this->assertSame([], $result['orphans']);
    }

    /** Cuatro cursos con la misma materia: el REA #40437 del catálogo real. */
    public function testTheSameSubjectTitleInFourCoursesGivesFourGroups(): void
    {
        $alignment = ['lrmi:educationalLevel' => [], 'schema:about' => []];
        foreach ([1, 2, 5, 6] as $n) {
            $alignment['lrmi:educationalLevel'][] = $this->value($n, "{$n}º Primaria", $n);
            $alignment['schema:about'][] = $this->value(100 + $n, 'Educación Física', $n);
        }

        $result = CurricularGrouping::build($alignment);

        $this->assertCount(4, $result['groups']);
        $this->assertSame(
            ['1º Primaria', '2º Primaria', '5º Primaria', '6º Primaria'],
            array_column($result['groups'], 'courseTitle')
        );
        foreach ($result['groups'] as $group) {
            $this->assertSame(['Educación Física'], $group['subjects']);
        }
    }
}
