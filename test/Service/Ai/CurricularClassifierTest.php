<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Content\ItemContext;
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
        $result = $this->make($this->resolver(), $llm)->classify(new ItemContext('recurso de ecuaciones', ''));

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
        $result = $this->make($this->resolver(), $llm)->classify(new ItemContext('proyecto transversal', ''));

        $this->assertSame([30, 31], $result['lrmi:teaches']);
        $this->assertSame([10, 12], $result['lrmi:educationalLevel']); // ambos cursos derivados
        $this->assertSame([20, 22], $result['schema:about']);
        $this->assertArrayNotHasKey('lrmi:assesses', $result);
    }

    public function testEtapaStepBiasesTowardInclusivenessButLaterStepsDoNot(): void
    {
        // La etapa solo acota (ADR-0009): ante duda de nivel se prima el recall
        // para no dejar fuera saberes/criterios. El sesgo NO debe contaminar la
        // materia ni las hojas (ahí la precisión sí importa: definen schema:about).
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // Etapa
            '{"selected":[1]}',   // Materia
            '{"selected":[2]}',   // Saberes
            '{"selected":[1]}',   // Criterios
        ]);
        $this->make($this->resolver(), $llm)->classify(new ItemContext('recurso de ecuaciones', ''));

        $this->assertStringContainsString('INCLUSIVO', $llm->calls[0]['messages'][0]['content']); // Etapa
        // TASK-056: inclusiveness only when the stage is not stated; a quoted stage wins.
        $this->assertStringContainsString('indica la etapa', $llm->calls[0]['messages'][0]['content']);
        $this->assertStringContainsString('EP = Educación Primaria', $llm->calls[0]['messages'][0]['content']);
        $this->assertStringNotContainsString('INCLUSIVO', $llm->calls[1]['messages'][0]['content']); // Materia
        $this->assertStringNotContainsString('INCLUSIVO', $llm->calls[2]['messages'][0]['content']); // Saberes
        $this->assertStringNotContainsString('INCLUSIVO', $llm->calls[3]['messages'][0]['content']); // Criterios
    }

    public function testCollectsJustificationsForLeavesByItemId(): void
    {
        // TASK-023: los pasos finos devuelven {"i":n,"why":"…"}; el porqué se
        // guarda por itemId de la hoja elegida (id 31 saber, id 40 criterio).
        $llm = new FakeLlmClient([
            '{"selected":[1]}',                                    // Etapa
            '{"selected":[1]}',                                    // Materia
            '{"selected":[{"i":2,"why":"trata ecuaciones"}]}',    // Saberes → id 31
            '{"selected":[{"i":1,"why":"resuelve ecuaciones"}]}', // Criterios → id 40
        ]);
        $classifier = $this->make($this->resolver(), $llm);
        $classifier->classify(new ItemContext('recurso de ecuaciones', ''));
        $j = $classifier->getJustifications();
        $this->assertSame('trata ecuaciones', $j['lrmi:teaches'][31]);
        $this->assertSame('resuelve ecuaciones', $j['lrmi:assesses'][40]);
    }

    public function testJustificationsResetAcrossClassifyCalls(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}',
            '{"selected":[{"i":2,"why":"x"}]}', '{"selected":[]}',
            '{"selected":[1]}', '{"selected":[1]}',
            '{"selected":[]}', '{"selected":[]}',
        ]);
        $c = $this->make($this->resolver(), $llm);
        $c->classify(new ItemContext('a', ''));
        $c->classify(new ItemContext('b', ''));
        $this->assertSame([], $c->getJustifications()['lrmi:teaches'] ?? []);
    }

    public function testDegradesWhenLeafStepOmitsReason(): void
    {
        // El LLM ignora la instrucción y devuelve enteros: la selección se
        // conserva; simplemente no hay justificación para ese id.
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}',
            '{"selected":[2]}', '{"selected":[]}',
        ]);
        $c = $this->make($this->resolver(), $llm);
        $result = $c->classify(new ItemContext('x', ''));
        $this->assertSame([31], $result['lrmi:teaches']); // selección intacta
        $this->assertSame([], $c->getJustifications()['lrmi:teaches'] ?? []);
    }

    public function testPassesTemperatureToEverySelectionCall(): void
    {
        // Perfil de inferencia compartido (paridad entre proveedores): la
        // temperatura llega a TODOS los pasos de la cascada, no solo al primero.
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // Etapa
            '{"selected":[1]}',   // Materia
            '{"selected":[2]}',   // Saberes
            '{"selected":[1]}',   // Criterios
        ]);
        $classifier = new CurricularClassifier(
            $llm,
            $this->resolver(),
            new PromptBuilder(),
            new ResponseParser(),
            1024,
            0.2
        );

        $classifier->classify(new ItemContext('recurso de ecuaciones', ''));

        $this->assertCount(4, $llm->calls);
        foreach ($llm->calls as $call) {
            $this->assertSame(0.2, $call['options']['temperature']);
        }
    }

    public function testOmitsTemperatureWhenNotConfigured(): void
    {
        // Sin temperatura configurada NO se envía (los Opus 4.6+ la rechazan).
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}', '{"selected":[]}', '{"selected":[]}']);

        $this->make($this->resolver(), $llm)->classify(new ItemContext('recurso', ''));

        $this->assertArrayNotHasKey('temperature', $llm->calls[0]['options']);
    }

    public function testNoEtapaReturnsEmpty(): void
    {
        $r = new FakeTermResolver(['etapa' => []]);
        $llm = new FakeLlmClient(['{"selected":[1]}']);
        $this->assertSame([], $this->make($r, $llm)->classify(new ItemContext('x', '')));
    }

    public function testNoSubjectReturnsEmpty(): void
    {
        $r = new FakeTermResolver(['etapa' => [['id' => 1, 'title' => 'ESO']]]);
        $r->families = [1 => []];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}']);
        $this->assertSame([], $this->make($r, $llm)->classify(new ItemContext('x', '')));
    }

    public function testDelimitationPassesEtapaAndSubjectToLeaves(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}', '{"selected":[]}', '{"selected":[]}',
        ]);
        $resolver = $this->resolver();
        $this->make($resolver, $llm)->classify(new ItemContext('contenido', ''));

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
        $result = $this->make($this->resolver(), $llm)->classify(new ItemContext('x', ''));
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
        $result = $this->make($r, $llm)->classify(new ItemContext('contenido de B', ''));
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
        $result = $this->make($this->resolver(), $llm)->classify(new ItemContext('contenido', ''));
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
        $this->make($r, $llm)->classify(new ItemContext('x', ''));

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
        $result = $this->make($r, $llm)->classify(new ItemContext('transversal', ''));
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
            '{"selected":[1,2]}', // criterios: se piden 1 y 2...
        ]);
        $result = $this->make($r, $llm)->classify(new ItemContext('ecuaciones', ''));
        // ...pero la lista ya está filtrada al curso 12 (solo id40), así que el
        // índice 2 queda fuera de rango. Si NO se filtrara, la lista sería
        // [id40, id41] y el resultado sería [40, 41]: la aserción prueba el filtro.
        $this->assertSame([40], $result['lrmi:assesses']);
    }

    public function testFamiliesAreDedupedAcrossEtapas(): void
    {
        // "Matemáticas" aparece en ambas etapas; tras dedup la lista de materias
        // es [Matemáticas, Física], así que el índice 2 = Física (no la 2ª
        // Matemáticas). Lo probamos por comportamiento: elegir la materia 2 debe
        // derivar del saber de Física, no del de Matemáticas.
        $r = new FakeTermResolver(['etapa' => [
            ['id' => 1, 'title' => 'Primaria'], ['id' => 2, 'title' => 'ESO'],
        ]]);
        $r->families = [
            1 => [['name' => 'Matemáticas']],
            2 => [['name' => 'Matemáticas'], ['name' => 'Física']],
        ];
        $r->leaves = [
            'lrmi:teaches|Matemáticas' => [['id' => 30, 'title' => 'M', 'description' => 'd',
                'block' => 'I', 'courseId' => 10, 'courseTitle' => '1º', 'subjectId' => 20]],
            'lrmi:teaches|Física' => [['id' => 50, 'title' => 'F', 'description' => 'd',
                'block' => 'II', 'courseId' => 11, 'courseTitle' => '1º', 'subjectId' => 21]],
            'lrmi:assesses' => [],
        ];
        $llm = new FakeLlmClient([
            '{"selected":[1,2]}', // ambas etapas
            '{"selected":[2]}',   // materia 2 = Física (si no hubiera dedup, sería Matemáticas)
            '{"selected":[1]}',   // saber de Física
            '{"selected":[]}',
        ]);
        $result = $this->make($r, $llm)->classify(new ItemContext('x', ''));
        $this->assertSame([50], $result['lrmi:teaches']);
        $this->assertSame([21], $result['schema:about']);
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
        $result = $this->make($r, $llm)->classify(new ItemContext('x', ''));
        $this->assertSame([40, 41], $result['lrmi:assesses']);
        // Curso/materia derivados solo de los criterios (fallback).
        $this->assertSame([12, 99], $result['lrmi:educationalLevel']);
        $this->assertSame([22, 23], $result['schema:about']);
    }

    // --- TASK-056: plausible courses, fair cap and labelled candidates --------

    /** Two subjects, one with two courses: the course step has something to choose. */
    private function coursedResolver(): FakeTermResolver
    {
        $r = $this->resolver();
        $r->families = [1 => [
            ['name' => 'Matemáticas', 'courses' => [['id' => 12, 'title' => '3º ESO'], ['id' => 10, 'title' => '1º ESO']]],
            ['name' => 'Tecnología', 'courses' => [['id' => 13, 'title' => '4º ESO']]],
        ]];
        return $r;
    }

    /** @return array<int,array<string,mixed>> */
    private function leaves(int $from, int $count, int $courseId, string $courseTitle, string $block = ''): array
    {
        $out = [];
        for ($id = $from; $id < $from + $count; $id++) {
            $out[] = ['id' => $id, 'title' => 'C' . $id, 'description' => 'Saber ' . $id, 'block' => $block,
                'courseId' => $courseId, 'courseTitle' => $courseTitle, 'subjectId' => $courseId + 100];
        }
        return $out;
    }

    public function testSubjectCandidatesShowTheirCourses(): void
    {
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}', '{"selected":[2]}', '{"selected":[]}',
            '{"selected":[]}']);
        $this->make($this->coursedResolver(), $llm)->classify(new ItemContext('ecuaciones, 3º ESO', ''));

        $subjects = $llm->calls[1]['messages'][0]['content'];
        $this->assertStringContainsString('Matemáticas (1º, 3º ESO)', $subjects);
        $this->assertStringContainsString('Tecnología (4º ESO)', $subjects);
    }

    public function testCourseStepBoundsTheLeavesToTheChosenCourses(): void
    {
        $resolver = $this->coursedResolver();
        $llm = new FakeLlmClient([
            '{"selected":[1]}',   // Etapa: ESO
            '{"selected":[1]}',   // Materia: Matemáticas
            '{"selected":[2]}',   // Curso: 3º ESO (ordered: 1º ESO, 3º ESO)
            '{"selected":[1]}',   // Saberes: only 3º ESO left → id 31
            '{"selected":[]}',    // Criterios
        ]);
        $result = $this->make($resolver, $llm)->classify(new ItemContext('ecuaciones, 3º ESO', ''));

        $courses = $llm->calls[2]['messages'][0]['content'];
        // «2º ciclo de EP» is 3º–4º Primaria, not 2º–3º: the model mixed cycle and
        // course numbers until it was told the LOMLOE equivalence (2026-10-06).
        $this->assertStringContainsString('segundo ciclo de Educación Primaria = 3º y 4º', $courses);
        $this->assertStringContainsString('segundo ciclo de Educación Infantil = 4º, 5º y 6º', $courses);
        $this->assertStringContainsString('1. 1º ESO', $courses);
        $this->assertStringContainsString('2. 3º ESO', $courses);
        $leafCalls = array_values(array_filter($resolver->calls, static fn (array $c): bool => isset($c['leaves'])));
        $this->assertSame([12], $leafCalls[0]['courses']);
        $this->assertSame([31], $result['lrmi:teaches']);
        $this->assertSame([12], $result['lrmi:educationalLevel']);
    }

    public function testCourseStepFallsBackToEveryCourseWhenNoneIsChosen(): void
    {
        $resolver = $this->coursedResolver();
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}', '{"selected":[]}', '{"selected":[]}',
            '{"selected":[]}']);
        $this->make($resolver, $llm)->classify(new ItemContext('números', ''));

        $leafCalls = array_values(array_filter($resolver->calls, static fn (array $c): bool => isset($c['leaves'])));
        $this->assertSame([10, 12], $leafCalls[0]['courses']);
        $this->assertSame(2, $this->stepByLabel($llm, 'Saberes básicos')['candidates']);
    }

    public function testCourseStepIsSkippedWhenThereIsOnlyOneCourse(): void
    {
        $resolver = $this->coursedResolver();
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[2]}', '{"selected":[]}', '{"selected":[]}']);
        $classifier = $this->make($resolver, $llm);
        $classifier->classify(new ItemContext('automatismos, 4º ESO', ''));

        // Etapa, Materia (Tecnología, one course) and the leaf steps: no course call.
        $this->assertNotContains('Curso', array_column($classifier->getTrace(), 'step'));
        $leafCalls = array_values(array_filter($resolver->calls, static fn (array $c): bool => isset($c['leaves'])));
        $this->assertSame([13], $leafCalls[0]['courses']);
    }

    public function testTheLeafCapIsSplitFairlyAcrossSubjectsAndCourses(): void
    {
        // Before TASK-056 the first 200 leaves won: Lengua never reached the model.
        $resolver = $this->resolver();
        $resolver->leaves = [
            'lrmi:teaches|Matemáticas' => $this->leaves(1000, 250, 10, '1º ESO'),
            'lrmi:teaches|Lengua' => $this->leaves(2000, 3, 11, '2º ESO'),
        ];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1,2]}', '{"selected":[]}']);
        $classifier = $this->make($resolver, $llm);
        $classifier->classify(new ItemContext('x', ''));

        $step = $this->stepByLabel($llm, 'Saberes básicos');
        $this->assertSame(200, $step['candidates']);
        foreach ([2000, 2001, 2002] as $id) {
            $this->assertStringContainsString('Saber ' . $id, $step['content']);
        }
        $cut = array_values(array_filter($classifier->getTrace(), static fn (array $t): bool => 'leaf_cap' === ($t['step'] ?? '')));
        $this->assertSame(['step' => 'leaf_cap', 'dimension' => 'lrmi:teaches', 'available' => 253, 'kept' => 200], $cut[0]);
    }

    public function testBlocksCarryTheirSubjectAndCourseAndStayApartPerSubject(): void
    {
        $resolver = $this->resolver();
        $resolver->families = [1 => [['name' => 'Matemáticas'], ['name' => 'Tecnología']]];
        $resolver->leaves = [
            'lrmi:teaches|Matemáticas' => $this->leaves(1000, 20, 10, '1º ESO', 'I. Proyectos'),
            'lrmi:teaches|Tecnología' => $this->leaves(2000, 20, 13, '4º ESO', 'I. Proyectos'),
        ];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1,2]}', '{"selected":[2]}', '{"selected":[]}']);
        $this->make($resolver, $llm)->classify(new ItemContext('x', ''));

        $blocks = $llm->calls[2]['messages'][0]['content'];
        $this->assertStringContainsString('1. [Matemáticas · 1º ESO] I. Proyectos', $blocks);
        $this->assertStringContainsString('2. [Tecnología · 4º ESO] I. Proyectos', $blocks);
        $step = $this->stepByLabel($llm, 'Saberes básicos');
        $this->assertSame(20, $step['candidates']);
        $this->assertStringContainsString('Saber 2000', $step['content']);
        $this->assertStringNotContainsString('Saber 1000', $step['content']);
    }

    /**
     * Prompt and candidate count of the call whose label is $label.
     *
     * @return array{candidates:int,content:string}
     */
    private function stepByLabel(FakeLlmClient $llm, string $label): array
    {
        foreach ($llm->calls as $call) {
            $content = $call['messages'][0]['content'];
            if (str_contains($content, 'Dimensión: ' . $label)) {
                return ['candidates' => preg_match_all('/^\d+\. /m', $content), 'content' => $content];
            }
        }
        $this->fail('No call for ' . $label);
    }

    // --- TASK-059: the trace says which leaves were gathered, shown and chosen, and what each call cost ---

    /** @return array<string,mixed> the trace entry of the leaf step $label */
    private function traceStep(CurricularClassifier $classifier, string $label): array
    {
        foreach ($classifier->getTrace() as $entry) {
            if ($label === ($entry['step'] ?? '')) {
                return $entry;
            }
        }
        $this->fail('No trace entry for ' . $label);
    }

    public function testLeafStepsTraceGatheredShownAndChosenIdsAndUsage(): void
    {
        $llm = new FakeLlmClient([
            '{"selected":[1]}', '{"selected":[1]}', '{"selected":[2]}', // stage, subject, course 3º ESO
            '{"selected":[{"i":1,"why":"x"}]}',                        // knowledge → id 31
            '{"selected":[{"i":1,"why":"y"}]}',                        // criteria → id 40
        ]);
        $classifier = $this->make($this->coursedResolver(), $llm);
        $classifier->classify(new ItemContext('ecuaciones, 3º ESO', ''));

        $knowledge = $this->traceStep($classifier, 'Saberes básicos');
        $this->assertSame([31], $knowledge['gathered_ids']);
        $this->assertSame([31], $knowledge['candidate_ids']);
        $this->assertSame([31], $knowledge['selected_ids']);
        $this->assertArrayHasKey('usage', $knowledge);
        $this->assertSame(['input_tokens', 'output_tokens', 'cost', 'model', 'ms'], array_keys($knowledge['usage']));

        $criteria = $this->traceStep($classifier, 'Criterios de evaluación');
        $this->assertSame([40], $criteria['gathered_ids']);
        $this->assertSame([40], $criteria['candidate_ids']);
        $this->assertSame([40], $criteria['selected_ids']);
        $this->assertArrayHasKey('usage', $this->traceStep($classifier, 'Etapa educativa'));
    }

    public function testGatheredIdsAreTakenBeforeTheCapAndTheBlockPrefilter(): void
    {
        $resolver = $this->resolver();
        $resolver->families = [1 => [['name' => 'Matemáticas'], ['name' => 'Tecnología']]];
        $resolver->leaves = [
            'lrmi:teaches|Matemáticas' => $this->leaves(1000, 20, 10, '1º ESO', 'I. Proyectos'),
            'lrmi:teaches|Tecnología' => $this->leaves(2000, 20, 13, '4º ESO', 'I. Proyectos'),
        ];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1,2]}', '{"selected":[2]}', '{"selected":[]}']);
        $classifier = $this->make($resolver, $llm);
        $classifier->classify(new ItemContext('x', ''));

        $knowledge = $this->traceStep($classifier, 'Saberes básicos');
        $this->assertCount(40, $knowledge['gathered_ids']);   // both subjects were gathered…
        $this->assertCount(20, $knowledge['candidate_ids']);  // …the block prefilter kept Tecnología
        $this->assertSame(2000, $knowledge['candidate_ids'][0]);
        $this->assertSame([], $knowledge['selected_ids']);
    }

    public function testAnEmptyLeafStepIsStillTraced(): void
    {
        $resolver = $this->resolver();
        $resolver->leaves = [];
        $llm = new FakeLlmClient(['{"selected":[1]}', '{"selected":[1]}']);
        $classifier = $this->make($resolver, $llm);
        $classifier->classify(new ItemContext('x', ''));

        $knowledge = $this->traceStep($classifier, 'Saberes básicos');
        $this->assertSame([], $knowledge['gathered_ids']);
        $this->assertSame([], $knowledge['candidate_ids']);
    }
}
