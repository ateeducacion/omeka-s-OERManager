<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\WorkflowReason;
use PHPUnit\Framework\TestCase;

final class WorkflowReasonTest extends TestCase
{
    public function testALiteralIsTrimmedCutAndHasNoDetail(): void
    {
        $reason = WorkflowReason::literal('  ' . str_repeat('a', 250) . ' ');
        $this->assertSame(str_repeat('a', 200), $reason->text);
        $this->assertNull($reason->dataType);
        $this->assertSame('', $reason->detail);
        $this->assertSame(
            [['type' => 'literal', 'property_id' => 6, '@value' => str_repeat('a', 200), 'is_public' => false]],
            $reason->noteValues(6)
        );
    }

    public function testATermIsWrittenWithItsTypeAndTheDetailAsASecondLiteral(): void
    {
        $reason = WorkflowReason::term(' Duplica un REA existente ', 'customvocab:42', ' ver el REA 12 ');
        $this->assertSame([
            ['type' => 'customvocab:42', 'property_id' => 6, '@value' => 'Duplica un REA existente', 'is_public' => false],
            ['type' => 'literal', 'property_id' => 6, '@value' => 'ver el REA 12', 'is_public' => false],
        ], $reason->noteValues(6));
    }

    public function testABlankDetailAddsNoValueAndALongOneIsCut(): void
    {
        $this->assertCount(1, WorkflowReason::term('Otro motivo', 'customvocab:41', "   \n ")->noteValues(6));
        $long = WorkflowReason::term('Otro motivo', 'customvocab:41', str_repeat('d', 250));
        $this->assertSame(str_repeat('d', 200), $long->detail);
    }

    public function testAnEmptyReasonWritesNothing(): void
    {
        $this->assertSame([], WorkflowReason::literal('   ')->noteValues(6));
    }
}
