<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\IdConstraints;
use OERManager\Service\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/** TASK-070: the unions the master view needs, as search fragments. */
final class IdConstraintsTest extends TestCase
{
    public function testTheQueueIsProposedOrPublicOutsideTheFlow(): void
    {
        $status = static fn (string $type, string $text): array => [
            'property' => WorkflowStatus::STATUS_TERM, 'type' => $type, 'text' => $text,
        ];
        $this->assertSame([
            ['property' => [$status('eq', WorkflowStatus::PROPOSED)]],
            ['is_public' => true, 'property' => [
                $status('neq', WorkflowStatus::PROPOSED),
                $status('neq', WorkflowStatus::PUBLISHED),
                $status('neq', WorkflowStatus::DISCARDED),
            ]],
        ], IdConstraints::queueBranches());
    }

    public function testNothingToConstrain(): void
    {
        $this->assertSame([], IdConstraints::groups([], ['dcterms:title']));
        $this->assertSame([], IdConstraints::groups(['status' => 'proposed'], []));
        $this->assertSame([], IdConstraints::groups(['missing' => ['licence']], ['dcterms:title']));
    }

    public function testStatusQueueAddsTheQueueGroup(): void
    {
        $this->assertSame([IdConstraints::queueBranches()], IdConstraints::groups(['status' => 'queue'], []));
    }

    public function testRequiredAddsOneNexBranchPerTerm(): void
    {
        $groups = IdConstraints::groups(
            ['missing' => ['licence', 'required']],
            ['dcterms:title', 'dcterms:license', 'dcterms:title']
        );
        $this->assertSame([[
            ['property' => [['property' => 'dcterms:title', 'type' => 'nex']]],
            ['property' => [['property' => 'dcterms:license', 'type' => 'nex']]],
        ]], $groups);
    }

    public function testRequiredWithoutTermsAddsNoGroup(): void
    {
        $this->assertSame([], IdConstraints::groups(['missing' => ['required']], []));
    }

    public function testBothGroupsInOrder(): void
    {
        $groups = IdConstraints::groups(['status' => 'queue', 'missing' => 'required'], ['dcterms:title']);
        $this->assertCount(2, $groups);
        $this->assertSame(IdConstraints::queueBranches(), $groups[0]);
    }

    public function testCombineUnionsInsideAndIntersectsAcross(): void
    {
        $this->assertSame([3, 5, 9], IdConstraints::combine([[[9, 3], [5, 3]]]));
        $this->assertSame([3, 5], IdConstraints::combine([[[9, 3], [5]], [[5, 3, 7]]]));
    }

    public function testCombineEmptyIntersection(): void
    {
        $this->assertSame([], IdConstraints::combine([[[1, 2]], [[3]]]));
        $this->assertSame([], IdConstraints::combine([[[], []]]));
        $this->assertSame([], IdConstraints::combine([]));
    }
}
