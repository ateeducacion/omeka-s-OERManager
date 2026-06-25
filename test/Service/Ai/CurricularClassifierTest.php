<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * TDD del clasificador curricular jerárquico top-down (NFR-008): Etapa→Curso→
 * Asignatura→{Saberes, Criterios}, acotando candidatos por el ancestro elegido.
 * El LLM devuelve ÍNDICES de la lista cerrada, que se mapean a ids por posición.
 */
final class CurricularClassifierTest extends TestCase
{
    private function resolver(): FakeTermResolver
    {
        return new FakeTermResolver([
            'etapa' => [['id' => 1, 'title' => 'ESO']],
            'lrmi:educationalLevel' => [['id' => 10, 'title' => '1º ESO'], ['id' => 11, 'title' => '2º ESO']],
            'schema:about' => [['id' => 20, 'title' => 'Matemáticas'], ['id' => 21, 'title' => 'Lengua']],
            'lrmi:teaches' => [['id' => 30, 'title' => 'Números'], ['id' => 31, 'title' => 'Álgebra']],
            'lrmi:assesses' => [['id' => 40, 'title' => 'Resuelve ecuaciones']],
        ]);
    }

    private function make(FakeTermResolver $resolver, FakeLlmClient $llm): CurricularClassifier
    {
        return new CurricularClassifier($llm, $resolver, new PromptBuilder(), new ResponseParser());
    }

    public function testTopDownCascadeMapsIndicesToIds(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":[1]}',     // ESO
            '{"selected":[1]}',     // 1º ESO
            '{"selected":[1]}',     // Matemáticas
            '{"selected":[1,2]}',   // Números, Álgebra
            '{"selected":[1]}',     // Resuelve ecuaciones
        ]);

        $result = $this->make($resolver, $llm)->classify('recurso de álgebra para ESO');

        // La Etapa solo acota el contexto; NO se escribe en el REA.
        $this->assertArrayNotHasKey('etapa', $result);
        $this->assertSame([10], $result['lrmi:educationalLevel']);
        $this->assertSame([20], $result['schema:about']);
        $this->assertSame([30, 31], $result['lrmi:teaches']);
        $this->assertSame([40], $result['lrmi:assesses']);
    }

    public function testCascadePropagatesAncestorContext(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $this->make($resolver, $llm)->classify('contenido');

        $aboutCall = $resolver->calls[2];
        $this->assertSame('schema:about', $aboutCall['dimension']);
        $this->assertSame(1, $aboutCall['context']['etapa'] ?? null);
        $this->assertSame(10, $aboutCall['context']['level'] ?? null);

        $teachesCall = $resolver->calls[3];
        $this->assertSame('lrmi:teaches', $teachesCall['dimension']);
        $this->assertSame(20, $teachesCall['context']['about'] ?? null);
    }

    public function testSingleSelectKeepsOnlyFirst(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":[1]}',
            '{"selected":[1,2]}', // el modelo se pasa en un single-select: solo uno
            '{"selected":[]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertSame([10], $result['lrmi:educationalLevel']);
    }

    public function testOutOfRangeIndicesAreDropped(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[99,1]}', // 99 no existe; 1 = Números
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertSame([30], $result['lrmi:teaches']);
    }

    public function testEmptySelectionOmitsDimension(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertArrayNotHasKey('lrmi:teaches', $result);
        $this->assertArrayNotHasKey('lrmi:assesses', $result);
    }

    public function testDuplicateTitlesResolveToDistinctIdsByPosition(): void
    {
        // Finding #4 (revisión adversaria): dos candidatos con el mismo título no
        // colapsan; el índice los distingue por posición → ambos ids alcanzables.
        $resolver = new FakeTermResolver([
            'etapa' => [['id' => 1, 'title' => 'ESO']],
            'lrmi:educationalLevel' => [['id' => 10, 'title' => '1º ESO']],
            'schema:about' => [['id' => 20, 'title' => 'Matemáticas']],
            'lrmi:teaches' => [['id' => 30, 'title' => 'Igual'], ['id' => 31, 'title' => 'Igual']],
            'lrmi:assesses' => [],
        ]);
        $llm = new FakeLlmClient([
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[1]}',
            '{"selected":[1,2]}', // ambos "Igual"
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertSame([30, 31], $result['lrmi:teaches']);
    }
}
