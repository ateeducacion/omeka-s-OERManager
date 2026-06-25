<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurricularClassifier;
use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * TDD del clasificador curricular jerárquico top-down (NFR-008): Etapa→Curso→
 * Asignatura→{Saberes, Criterios}, acotando candidatos por el ancestro elegido y
 * mapeando las etiquetas del LLM a ids de la lista cerrada.
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

    public function testTopDownCascadeMapsLabelsToIds(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":["ESO"]}',
            '{"selected":["1º ESO"]}',
            '{"selected":["Matemáticas"]}',
            '{"selected":["Números","Álgebra"]}',
            '{"selected":["Resuelve ecuaciones"]}',
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
            '{"selected":["ESO"]}',
            '{"selected":["1º ESO"]}',
            '{"selected":["Matemáticas"]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $this->make($resolver, $llm)->classify('contenido');

        // Orden de enumeración: etapa, level, about, teaches, assesses.
        $aboutCall = $resolver->calls[2];
        $this->assertSame('schema:about', $aboutCall['dimension']);
        $this->assertSame(1, $aboutCall['context']['etapa'] ?? null);
        $this->assertSame(10, $aboutCall['context']['level'] ?? null);

        $teachesCall = $resolver->calls[3];
        $this->assertSame('lrmi:teaches', $teachesCall['dimension']);
        $this->assertSame(20, $teachesCall['context']['about'] ?? null);
    }

    public function testCaseInsensitiveLabelMatching(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":["eso"]}',
            '{"selected":["1º eso"]}',
            '{"selected":["MATEMÁTICAS"]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertSame([10], $result['lrmi:educationalLevel']);
        $this->assertSame([20], $result['schema:about']);
    }

    public function testSingleSelectKeepsOnlyFirst(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":["ESO"]}',
            '{"selected":["1º ESO","2º ESO"]}', // el modelo se pasa: solo uno
            '{"selected":[]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertSame([10], $result['lrmi:educationalLevel']);
    }

    public function testHallucinatedLabelsAreDropped(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":["ESO"]}',
            '{"selected":["1º ESO"]}',
            '{"selected":["Matemáticas"]}',
            '{"selected":["Geografía","Números"]}', // "Geografía" no es candidato
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertSame([30], $result['lrmi:teaches']);
    }

    public function testEmptySelectionOmitsDimension(): void
    {
        $resolver = $this->resolver();
        $llm = new FakeLlmClient([
            '{"selected":["ESO"]}',
            '{"selected":["1º ESO"]}',
            '{"selected":["Matemáticas"]}',
            '{"selected":[]}',
            '{"selected":[]}',
        ]);
        $result = $this->make($resolver, $llm)->classify('contenido');
        $this->assertArrayNotHasKey('lrmi:teaches', $result);
        $this->assertArrayNotHasKey('lrmi:assesses', $result);
    }
}
