<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\IdConstraintResolver;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

final class BatchProposeEligibilityTest extends TestCase
{
    private array $searches = [];

    private function eligibility(array $queue, array $withProposal): BatchProposeEligibility
    {
        $resolver = $this->createMock(IdConstraintResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            fn (array $query) => ['status' => 'queue'] === $query ? $queue : null
        );
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(
            function (string $resource, array $params, array $options = []) use ($withProposal) {
                $this->searches[] = [$resource, $params, $options];
                return new Response(array_values(array_intersect($params['id'], $withProposal)));
            }
        );
        return new BatchProposeEligibility($api, $resolver);
    }

    public function testTheSelectionIsSplitInThreeBuckets(): void
    {
        $split = $this->eligibility([1, 2, 3, 9], [2])->split([1, 2, 3, 4, 5]);
        $this->assertSame(['enter' => [1, 3], 'out_of_queue' => [4, 5], 'has_proposal' => [2]], $split);
        $this->assertSame([[
            'items',
            ['id' => [1, 2, 3], 'property' => [['property' => 'curation:data', 'type' => 'sw', 'text' => 'OERManager/ai-proposal/1']]],
            ['returnScalar' => 'id'],
        ]], $this->searches);
    }

    public function testNothingInTheQueueAsksNoSecondSearch(): void
    {
        $split = $this->eligibility([], [])->split([4, 5]);
        $this->assertSame(['enter' => [], 'out_of_queue' => [4, 5], 'has_proposal' => []], $split);
        $this->assertSame([], $this->searches);
    }

    public function testAnEmptySelectionIsEmpty(): void
    {
        $this->assertSame(['enter' => [], 'out_of_queue' => [], 'has_proposal' => []], $this->eligibility([1], [])->split([]));
    }

    public function testStillEligible(): void
    {
        $eligibility = $this->eligibility([1, 2], [2]);
        $this->assertTrue($eligibility->stillEligible(1));
        $this->assertFalse($eligibility->stillEligible(2));
        $this->assertFalse($eligibility->stillEligible(3));
    }
}
