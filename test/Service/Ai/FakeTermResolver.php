<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\TermResolverInterface;

/**
 * Resolutor de términos falso: candidatos configurados por dimensión, registra
 * las llamadas (dimensión + contexto) para verificar la cascada contextual.
 */
final class FakeTermResolver implements TermResolverInterface
{
    /** @var array<string,array<int,array{id:int,title:string}>> */
    private array $byDimension;
    /** @var array<int,array{dimension:string,context:array}> */
    public array $calls = [];

    /** @param array<string,array<int,array{id:int,title:string}>> $byDimension */
    public function __construct(array $byDimension = [])
    {
        $this->byDimension = $byDimension;
    }

    public function listCandidates(string $dimension, array $context = []): array
    {
        $this->calls[] = ['dimension' => $dimension, 'context' => $context];
        return $this->byDimension[$dimension] ?? [];
    }
}
