<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\PromptBuilder;
use OERManager\Service\Ai\ResponseParser;
use OERManager\Service\Ai\TagClassifier;
use OERManager\Service\Content\ItemContext;
use PHPUnit\Framework\TestCase;

/**
 * TDD del clasificador de ejes temáticos (dcterms:relation): los ejes en un
 * único prompt; el LLM devuelve índices que se mapean a ids por posición.
 */
final class TagClassifierTest extends TestCase
{
    private function make(FakeTermResolver $resolver, FakeLlmClient $llm): TagClassifier
    {
        return new TagClassifier($llm, $resolver, new PromptBuilder(), new ResponseParser());
    }

    public function testSelectsAxesAndMapsToIds(): void
    {
        $resolver = new FakeTermResolver([
            'dcterms:relation' => [
                ['id' => 50, 'title' => 'Patrimonio'],
                ['id' => 51, 'title' => 'STEAM'],
                ['id' => 52, 'title' => 'AICLE'],
            ],
        ]);
        $llm = new FakeLlmClient(['{"selected":[2,1]}']); // STEAM, Patrimonio

        $result = $this->make($resolver, $llm)->classify(new ItemContext('recurso STEAM sobre patrimonio', ''));

        $this->assertSame([51, 50], $result['dcterms:relation']);
        $this->assertCount(1, $resolver->calls);
        $this->assertSame('dcterms:relation', $resolver->calls[0]['dimension']);
    }

    public function testNoAxesConfiguredReturnsEmpty(): void
    {
        $resolver = new FakeTermResolver([]);
        $llm = new FakeLlmClient(['{"selected":[1]}']);
        $this->assertSame([], $this->make($resolver, $llm)->classify(new ItemContext('contenido', '')));
    }

    public function testNoSelectionOmitsDimension(): void
    {
        $resolver = new FakeTermResolver(['dcterms:relation' => [['id' => 50, 'title' => 'Patrimonio']]]);
        $llm = new FakeLlmClient(['{"selected":[]}']);
        $this->assertSame([], $this->make($resolver, $llm)->classify(new ItemContext('contenido', '')));
    }
}
