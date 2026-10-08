<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Llm\ChatResult;
use OERManager\Service\Llm\DecisionModelInterface;
use OERManager\Service\Llm\DecisionResult;
use OERManager\Service\Llm\LlmException;

/** Decision model double: P(yes) per question from a callback; records every request. */
final class FakeDecisionModel implements DecisionModelInterface
{
    /** @var list<array{state:string,questions:array<string,array<string,mixed>>}> */
    public array $calls = [];

    /** @param callable(string,array<string,mixed>):?float $probability null = no answer */
    public function __construct(private $probability, private bool $fail = false)
    {
    }

    public function decide(string $state, array $questions): DecisionResult
    {
        $this->calls[] = ['state' => $state, 'questions' => $questions];
        if ($this->fail) {
            throw new LlmException('decisions provider down');
        }
        $answers = [];
        foreach ($questions as $id => $question) {
            $p = ($this->probability)((string) $id, $question);
            if (null !== $p) {
                $answers[(string) $id] = ['type' => 'noul', 'noul' => $p];
            }
        }
        return new DecisionResult($answers, new ChatResult('', 100 * count($questions), 1, 0.00001, 'typesafe/jev-fake'));
    }
}
