<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * TDD del clasificador bottom-up (ADR-0010): Etapa + familia de materia delimitan
 * (Fase A); el LLM selecciona saberes/criterios por descripción (Fase B/C); el
 * Curso (lrmi:educationalLevel) y la Materia (schema:about) se DERIVAN de las
 * hojas elegidas (Fase D) → coherencia por construcción.
 */
final class CurricularClassifierTest extends TestCase
{
    private function resolver(): FakeTermResolver
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas'], ['name' => 'Lengua']]];
        $r->leaves = [
            'lrmi:teaches' => [
                ['id' => 30, 'title' => 'MAT01.1', 'description' => 'Números naturales',
                    'block' => 'I. Sentido numérico', 'courseId' => 10, 'courseTitle' => '1º ESO', 'subjectId' => 20],
                ['id' => 31, 'title' => 'MAT03.5', 'description' => 'Ecuaciones de primer grado',
                    'block' => 'IV. Sentido algebraico', 'courseId' => 12, 'courseTitle' => '3º ESO', 'subjectId' => 22],
            ],
            'lrmi:assesses' => [
                ['id' => 40, 'title' => 'MATCE.1', 'description' => 'Resuelve ecuaciones',
                    'block' => '', 'courseId' => 12, 'courseTitle' => '3º ESO', 'subjectId' => 22],
            ],
        ];
        return $r;
    }

    private function make(FakeTermResolver $resolver, FakeLlmClient $llm): CurricularClassifier
    {
        return new CurricularClassifier($llm, $resolver, new PromptBuilder(), new ResponseParser());
    }

    public function testDerivesCourseAndSubjectFromSelectedLeaves(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // Etapa: ESO
            '{"selected":[1]}',   // Materia: Matemáticas
            '{"selected":[2]}',   // Saberes: Ecuaciones (id 31, course 12, subject 22)
            '{"selected":[1]}',   // Criterios: id 40 (course 12, subject 22)
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('recurso de ecuaciones');

        $this->assertSame([31], $result['lrmi:teaches']);
        $this->assertSame([40], $result['lrmi:assesses']);
        // Curso y materia DERIVADOS de las hojas (coherencia por construcción):
        $this->assertSame([12], $result['lrmi:educationalLevel']);
        $this->assertSame([22], $result['schema:about']);
        // La etapa nunca se escribe (ADR-0009):
        $this->assertArrayNotHasKey('etapa', $result);
    }

    public function testCrossGradeSelectionDerivesMultipleCourses(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}',     // ESO
            '{"selected":[1]}',     // Matemáticas
            '{"selected":[1,2]}',   // saberes de 1º (id30,course10,subj20) y 3º (id31,course12,subj22)
            '{"selected":[]}',      // criterios: ninguno
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('proyecto transversal');

        $this->assertSame([30, 31], $result['lrmi:teaches']);
        $this->assertSame([10, 12], $result['lrmi:educationalLevel']); // ambos cursos derivados
        $this->assertSame([20, 22], $result['schema:about']);
        $this->assertArrayNotHasKey('lrmi:assesses', $result);
    }

    public function testNoEtapaReturnsEmpty(): void
    {
        $r = new FakeTermResolver(['etapa' => []]);
        $llm = new FakeLlmClient(['{"selected":[1]}']);
        $this->assertSame([], $this->make($r, $llm)->classify('x'));
    }

    public function testNoSubjectReturnsEmpty(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => []];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}']);
        $this->assertSame([], $this->make($r, $llm)->classify('x'));
    }

    public function testDelimitationPassesEtapaAndSubjectToLeaves(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}', '{"selected":[]}', '{"selected":[]}',
        ]);
        $resolver = $this->resolver();
        $this->make($resolver, $llm)->classify('contenido');

        $leafCalls = array_values(array_filter($resolver->calls, static fn ($c) => isset($c['leaves'])));
        $this->assertSame('lrmi:teaches', $leafCalls[0]['leaves']);
        $this->assertSame(1, $leafCalls[0]['etapa']);
        $this->assertSame('Matemáticas', $leafCalls[0]['subject']);
    }

    public function testOutOfRangeLeafIndicesAreDropped(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}', '{"selected":[99,1]}', '{"selected":[]}',
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('x');
        $this->assertSame([30], $result['lrmi:teaches']); // 99 descartado; 1 = id 30
        $this->assertSame([10], $result['lrmi:educationalLevel']);
    }

    public function testBlockPrefilterTriggersAboveThreshold(): void
    {
        // 31 saberes en 2 bloques (> BLOCK_THRESHOLD=30) → paso de bloques antes
        // de elegir saberes. El LLM elige el bloque "B" y luego el saber de "B".
        $leaves = [];
        for ($i = 1; $i <= 30; $i++) {
            $leaves[] = ['id' => 100 + $i, 'title' => "A{$i}", 'description' => "desc A {$i}",
                'block' => 'Bloque A', 'courseId' => 10, 'courseTitle' => '1º ESO', 'subjectId' => 20];
        }
        $leaves[] = ['id' => 200, 'title' => 'B1', 'description' => 'desc B 1',
            'block' => 'Bloque B', 'courseId' => 10, 'courseTitle' => '1º ESO', 'subjectId' => 20];
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas']]];
        $r->leaves = ['lrmi:teaches' => $leaves, 'lrmi:assesses' => []];

        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // ESO
            '{"selected":[1]}',   // Matemáticas
            '{"selected":[2]}',   // Bloques: ["Bloque A","Bloque B"] → elige "Bloque B"
            '{"selected":[1]}',   // Saberes (ya filtrados a Bloque B): id 200
            '{"selected":[]}',    // Criterios
        ]);
        $result = $this->make($r, $llm)->classify('contenido de B');
        $this->assertSame([200], $result['lrmi:teaches']);
    }

    public function testEmptyLeafSelectionsOmitDerivedDimensions(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', // Etapa: ESO
            '{"selected":[1]}', // Materia: Matemáticas
            '{"selected":[]}',  // Saberes: ninguno
            '{"selected":[]}',  // Criterios: ninguno
        ]);
        $result = $this->make($this->resolver(), $llm)->classify('contenido');
        $this->assertSame([], $result);
        $this->assertArrayNotHasKey('lrmi:educationalLevel', $result);
        $this->assertArrayNotHasKey('schema:about', $result);
    }

    public function testMultipleEtapasAreQueried(): void
    {
        $r = new FakeTermResolver(['etapa' => [
            ['id' => 1, 'title' => 'Primaria'], ['id' => 2, 'title' => 'ESO'],
        ]]);
        $r->families = [1 => [['name' => 'Conocimiento del Medio']], 2 => [['name' => 'Biología y Geología']]];
        $r->leaves = ['lrmi:teaches' => [], 'lrmi:assesses' => []];
        $llm = new FakeLlmClient([
            '{"selected":[1,2]}', // dos etapas
            '{"selected":[1,2]}', // dos materias
            '{"selected":[]}', '{"selected":[]}',
        ]);
        $this->make($r, $llm)->classify('x');

        $families = array_values(array_filter($r->calls, static fn ($c) => isset($c['subjectFamilies'])));
        $this->assertSame([1, 2], array_map(static fn ($c) => $c['subjectFamilies'], $families));
        $etapasEnHojas = array_values(array_unique(array_map(
            static fn ($c) => $c['etapa'],
            array_filter($r->calls, static fn ($c) => isset($c['leaves']))
        )));
        $this->assertSame([1, 2], $etapasEnHojas);
    }

    public function testLeavesGatheredAcrossMultipleSubjects(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas'], ['name' => 'Física y Química']]];
        $r->leaves = [
            'lrmi:teaches|Matemáticas' => [['id' => 30, 'title' => 'M', 'description' => 'Números',
                'block' => 'I', 'courseId' => 10, 'courseTitle' => '1º', 'subjectId' => 20]],
            'lrmi:teaches|Física y Química' => [['id' => 50, 'title' => 'F', 'description' => 'Energía',
                'block' => 'II', 'courseId' => 11, 'courseTitle' => '1º', 'subjectId' => 21]],
            'lrmi:assesses' => [],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1]}',     // ESO
            '{"selected":[1,2]}',   // ambas materias
            '{"selected":[1,2]}',   // ambos saberes (id30, id50)
            '{"selected":[]}',
        ]);
        $result = $this->make($r, $llm)->classify('transversal');
        $this->assertSame([30, 50], $result['lrmi:teaches']);
        $this->assertSame([10, 11], $result['lrmi:educationalLevel']);
        $this->assertSame([20, 21], $result['schema:about']);
    }

    public function testCriteriaAreConstrainedToCoursesOfSelectedSaberes(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas']]];
        $r->leaves = [
            'lrmi:teaches' => [['id' => 30, 'title' => 'M', 'description' => 'Ecuaciones',
                'block' => 'IV', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22]],
            'lrmi:assesses' => [
                ['id' => 40, 'title' => 'CEX', 'description' => 'crit curso 12',
                    'block' => '', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22],
                ['id' => 41, 'title' => 'CEY', 'description' => 'crit curso 99',
                    'block' => '', 'courseId' => 99, 'courseTitle' => '4º', 'subjectId' => 22],
            ],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // ESO
            '{"selected":[1]}',   // Matemáticas
            '{"selected":[1]}',   // saber id30 (curso 12)
            '{"selected":[1,2]}', // criterios: la lista YA filtrada a curso 12 solo tiene id40
        ]);
        $result = $this->make($r, $llm)->classify('ecuaciones');
        // Solo el criterio del curso 12 es elegible; el del curso 99 se filtró fuera.
        $this->assertSame([40], $result['lrmi:assesses']);
    }

    public function testCriteriaFallbackWhenNoSaberesSelected(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => [['name' => 'Matemáticas']]];
        $r->leaves = [
            'lrmi:teaches' => [['id' => 30, 'title' => 'M', 'description' => 'd',
                'block' => 'IV', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22]],
            'lrmi:assesses' => [
                ['id' => 40, 'title' => 'CEX', 'description' => 'c12',
                    'block' => '', 'courseId' => 12, 'courseTitle' => '3º', 'subjectId' => 22],
                ['id' => 41, 'title' => 'CEY', 'description' => 'c99',
                    'block' => '', 'courseId' => 99, 'courseTitle' => '4º', 'subjectId' => 23],
            ],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // ESO
            '{"selected":[1]}',   // Matemáticas
            '{"selected":[]}',    // saberes: ninguno → sin cursos derivados
            '{"selected":[1,2]}', // criterios: sin filtro, ambos elegibles
        ]);
        $result = $this->make($r, $llm)->classify('x');
        $this->assertSame([40, 41], $result['lrmi:assesses']);
        // Curso/materia derivados solo de los criterios (fallback).
        $this->assertSame([12, 99], $result['lrmi:educationalLevel']);
        $this->assertSame([22, 23], $result['schema:about']);
    }
}
