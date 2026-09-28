<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/** A batch selection the preview refuses, with the code the UI explains. */
final class BatchSelectionException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
