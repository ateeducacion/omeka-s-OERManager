<?php

namespace OERManager\Service\Ai;

/** Reporter no-op: default cuando no hay Job (propose síncrono, tests). */
final class NullProgressReporter implements ProgressReporter
{
    public function report(string $step, int $done, int $total): void
    {
    }

    public function shouldStop(): bool
    {
        return false;
    }
}
