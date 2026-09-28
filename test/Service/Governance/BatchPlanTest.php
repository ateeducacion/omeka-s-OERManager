<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchRequest;
use PHPUnit\Framework\TestCase;

final class BatchPlanTest extends TestCase
{
    private const RAW = ['dcterms:license' => ['https://x/by/4.0/'], 'dcterms:creator' => ['Ana']];

    public function testFillSummarySkipsItemsThatHaveAValue(): void
    {
        $plan = new BatchPlan(3, [1, 2, 3, 4], self::RAW, BatchRequest::MODE_FILL);

        $summary = $plan->summary(['dcterms:license' => 1, 'dcterms:creator' => 0]);

        $this->assertSame(['total' => 4, 'write' => 3, 'skipped_has_value' => 1], $summary['dcterms:license']);
        $this->assertSame(['total' => 4, 'write' => 4, 'skipped_has_value' => 0], $summary['dcterms:creator']);
        $this->assertSame(4, BatchPlan::writeTotal($summary));
    }

    public function testReplaceSummaryCountsOverwrites(): void
    {
        $plan = new BatchPlan(3, [1, 2], self::RAW, BatchRequest::MODE_REPLACE);

        $summary = $plan->summary(['dcterms:license' => 2, 'dcterms:creator' => 0]);

        $this->assertSame(['total' => 2, 'write' => 2, 'overwrite' => 2], $summary['dcterms:license']);
    }

    public function testWriteTotalIsZeroWhenFillFindsEverythingTaken(): void
    {
        $plan = new BatchPlan(3, [1, 2], ['dcterms:license' => ['https://x/']], BatchRequest::MODE_FILL);

        $this->assertSame(0, BatchPlan::writeTotal($plan->summary(['dcterms:license' => 2])));
    }

    public function testArrayRoundTripAndRejectionOfBrokenData(): void
    {
        $plan = new BatchPlan(3, [5, 6], self::RAW, BatchRequest::MODE_FILL);

        $copy = BatchPlan::fromArray($plan->toArray());

        $this->assertSame([5, 6], $copy->ids);
        $this->assertSame(3, $copy->ownerId);
        $this->assertSame(self::RAW, $copy->raw);
        $this->assertNull(BatchPlan::fromArray(['ids' => 'x']));
        $this->assertNull(BatchPlan::fromArray([]));
    }
}
