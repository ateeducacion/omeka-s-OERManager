<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use OERManager\Service\Llm\ChatResult;
use OERManager\Service\Llm\LlmUsage;
use PHPUnit\Framework\TestCase;

/** TASK-059: one trace entry per LLM call with tokens, real cost, serving model and latency. */
final class LlmUsageTest extends TestCase
{
    public function testDescribesTokensCostModelAndLatency(): void
    {
        $usage = LlmUsage::of(new ChatResult('x', 120, 30, 0.0021, 'openai/gpt-4o-mini'), microtime(true) - 0.25);

        $this->assertSame(120, $usage['input_tokens']);
        $this->assertSame(30, $usage['output_tokens']);
        $this->assertSame(0.0021, $usage['cost']);
        $this->assertSame('openai/gpt-4o-mini', $usage['model']);
        $this->assertGreaterThanOrEqual(250, $usage['ms']);
        $this->assertLessThan(2000, $usage['ms']);
    }

    public function testUnknownCostStaysUnknown(): void
    {
        $this->assertNull(LlmUsage::of(new ChatResult('x'), microtime(true))['cost']);
    }
}
