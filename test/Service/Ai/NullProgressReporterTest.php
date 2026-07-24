<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\NullProgressReporter;
use PHPUnit\Framework\TestCase;

final class NullProgressReporterTest extends TestCase
{
    public function testNeverStopsAndReportIsNoop(): void
    {
        $reporter = new NullProgressReporter();
        $reporter->report('cualquier fase', 1, 5); // no debe lanzar
        $this->assertFalse($reporter->shouldStop());
    }
}
