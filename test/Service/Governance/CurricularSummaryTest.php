<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurricularSummary;
use PHPUnit\Framework\TestCase;

/**
 * La celda de anclaje curricular muestra los PARES materia→curso a los que el
 * REA está vinculado, agrupados por materia.
 *
 * El motivo no es solo el espacio. Deduplicar materias por un lado y cursos por
 * otro producía una lectura FALSA: el item 4362 del catálogo real tiene
 * «Biología y Geología» en 1º ESO y «Descubrimiento y exploración del entorno»
 * en tres cursos de Infantil, y la celda plana los mezclaba insinuando que
 * Biología estaba en Infantil.
 */
final class CurricularSummaryTest extends TestCase
{
    private function pair(string $subject, string $course): array
    {
        return ['subject' => $subject, 'course' => $course, 'isLiteral' => false];
    }

    public function testSinglePair(): void
    {
        $result = CurricularSummary::summarise([$this->pair('Biología y Geología', '1º ESO')], []);

        $this->assertSame('Biología y Geología', $result['groups'][0]['subject']);
        $this->assertSame(['1º ESO'], $result['groups'][0]['courses']);
        $this->assertFalse($result['hasLiteral']);
    }

    /** El caso que motiva la agrupación: una materia repetida en varios cursos. */
    public function testSameSubjectGroupsItsCourses(): void
    {
        $result = CurricularSummary::summarise([
            $this->pair('Conocimiento del Medio', '3º Primaria'),
            $this->pair('Conocimiento del Medio', '4º Primaria'),
            $this->pair('Conocimiento del Medio', '5º Primaria'),
        ], []);

        $this->assertCount(1, $result['groups']);
        $this->assertSame(['3º Primaria', '4º Primaria', '5º Primaria'], $result['groups'][0]['courses']);
    }

    /** El caso 4362: dos materias que NO deben mezclarse. */
    public function testDistinctSubjectsKeepTheirOwnCourses(): void
    {
        $result = CurricularSummary::summarise([
            $this->pair('Biología y Geología', '1º ESO'),
            $this->pair('Descubrimiento', '4º Infantil de 3 años'),
            $this->pair('Descubrimiento', '5º Infantil de 4 años'),
        ], []);

        $this->assertCount(2, $result['groups']);
        $this->assertSame(['1º ESO'], $result['groups'][0]['courses']);
        $this->assertSame(
            ['4º Infantil de 3 años', '5º Infantil de 4 años'],
            $result['groups'][1]['courses']
        );
    }

    public function testRepeatedPairIsNotListedTwice(): void
    {
        $result = CurricularSummary::summarise([
            $this->pair('Matemáticas', '1º ESO'),
            $this->pair('Matemáticas', '1º ESO'),
        ], []);

        $this->assertSame(['1º ESO'], $result['groups'][0]['courses']);
    }

    /**
     * 7 de los 19 REA del catálogo real tienen un curso que ninguna materia
     * cubre. Perderlos en silencio sería peor que la celda que se sustituye.
     */
    public function testOrphanCoursesSurvive(): void
    {
        $result = CurricularSummary::summarise(
            [$this->pair('Matemáticas', '1º ESO')],
            ['2º ESO']
        );

        $this->assertSame(['2º ESO'], $result['orphanCourses']);
    }

    public function testOrphanCoursesAreDeduplicated(): void
    {
        $result = CurricularSummary::summarise([], ['2º ESO', '2º ESO']);

        $this->assertSame(['2º ESO'], $result['orphanCourses']);
    }

    public function testAnchoredOnlyToCoursesIsStillShown(): void
    {
        $result = CurricularSummary::summarise([], ['1º Bachillerato']);

        $this->assertSame([], $result['groups']);
        $this->assertSame(['1º Bachillerato'], $result['orphanCourses']);
    }

    public function testALiteralAnywhereRaisesTheFlag(): void
    {
        $result = CurricularSummary::summarise(
            [['subject' => 'Matemáticas', 'course' => '1º ESO', 'isLiteral' => true]],
            []
        );

        $this->assertTrue($result['hasLiteral']);
        $this->assertTrue($result['groups'][0]['isLiteral']);
    }

    /** Un literal sin título sigue levantando la bandera (regresión de la rebanada 2). */
    public function testLiteralWithBlankSubjectStillRaisesTheFlag(): void
    {
        $result = CurricularSummary::summarise(
            [['subject' => '   ', 'course' => '', 'isLiteral' => true]],
            []
        );

        $this->assertTrue($result['hasLiteral']);
        $this->assertSame([], $result['groups']);
    }

    public function testEmptyInputIsNotAnError(): void
    {
        $result = CurricularSummary::summarise([], []);

        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['orphanCourses']);
        $this->assertFalse($result['hasLiteral']);
        $this->assertSame('', $result['tooltip']);
    }

    public function testTooltipListsEveryPairInFull(): void
    {
        $result = CurricularSummary::summarise([
            $this->pair('Matemáticas', '1º ESO'),
            $this->pair('Matemáticas', '2º ESO'),
        ], ['3º ESO']);

        $this->assertSame('Matemáticas: 1º ESO, 2º ESO — Sin materia: 3º ESO', $result['tooltip']);
    }

    // --- Abreviatura de cursos ---------------------------------------------

    /**
     * «3º Primaria, 4º Primaria, 5º Primaria» comparten la última palabra y sus
     * partes distintivas son de UNA palabra: se puede factorizar sin perder nada.
     */
    public function testCoursesSharingATailAreAbbreviated(): void
    {
        $this->assertSame(
            '3º · 4º · 5º Primaria',
            CurricularSummary::abbreviateCourses(['3º Primaria', '4º Primaria', '5º Primaria'])
        );
    }

    /**
     * «4º Infantil de 3 años» y «5º Infantil de 4 años» comparten «años», pero lo
     * que queda delante son varias palabras: factorizar produciría «4º Infantil
     * de 3 · 5º Infantil de 4 años», que es peor que no abreviar. No se abrevia.
     */
    public function testCoursesWithMultiWordRemaindersAreNotAbbreviated(): void
    {
        $this->assertSame(
            '4º Infantil de 3 años · 5º Infantil de 4 años',
            CurricularSummary::abbreviateCourses(['4º Infantil de 3 años', '5º Infantil de 4 años'])
        );
    }

    public function testCoursesWithNothingInCommonAreListedInFull(): void
    {
        $this->assertSame(
            '1º ESO · 2º Bachillerato',
            CurricularSummary::abbreviateCourses(['1º ESO', '2º Bachillerato'])
        );
    }

    public function testASingleCourseIsNeverAbbreviated(): void
    {
        $this->assertSame('3º Primaria', CurricularSummary::abbreviateCourses(['3º Primaria']));
    }

    public function testNoCoursesGivesEmptyString(): void
    {
        $this->assertSame('', CurricularSummary::abbreviateCourses([]));
    }

    /** Un curso de una sola palabra no deja parte distintiva: no se abrevia. */
    public function testIdenticalSingleWordCoursesAreNotAbbreviated(): void
    {
        $this->assertSame('Primaria · Primaria', CurricularSummary::abbreviateCourses(['Primaria', 'Primaria']));
    }
}
