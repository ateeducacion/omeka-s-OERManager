<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\TermResolverInterface;

/**
 * Resolutor de términos falso para TDD del flujo bottom-up: candidatos por
 * dimensión (etapa/ejes), familias de materia por etapa, y hojas por dimensión.
 * Registra las llamadas para verificar la delimitación y la derivación.
 */
final class FakeTermResolver implements TermResolverInterface
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $byDimension;
    /** @var array<int,array<int,array{name:string}>> */
    public array $families = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $leaves = [];
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];

    /** @param array<string,array<int,array<string,mixed>>> $byDimension */
    public function __construct(array $byDimension = [])
    {
        $this->byDimension = $byDimension;
    }

    public function listCandidates(string $dimension, array $context = []): array
    {
        $this->calls[] = ['dimension' => $dimension, 'context' => $context];
        return $this->byDimension[$dimension] ?? [];
    }

    public function listSubjectFamilies(int $etapaId): array
    {
        $this->calls[] = ['subjectFamilies' => $etapaId];
        return $this->families[$etapaId] ?? [];
    }

    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array
    {
        $this->calls[] = ['leaves' => $dimension, 'etapa' => $etapaId, 'subject' => $subjectName];
        return $this->leaves["{$dimension}|{$subjectName}"] ?? $this->leaves[$dimension] ?? [];
    }
}
