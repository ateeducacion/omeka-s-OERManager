<?php

namespace OERManager\Service\Ai;

use OERManager\Service\CurriculumSearch;

/**
 * Adapta CurriculumSearch (TASK-004) al puerto TermResolverInterface: enumera el
 * conjunto acotado de candidatos de cada dimensión para mostrárselos al LLM. Usa
 * un límite de enumeración mayor que el de autocompletar (cubre los 72 ejes y los
 * hijos de una asignatura) pero acotado para no disparar el coste de tokens.
 *
 * Verificado en el contenedor (depende de CurriculumSearch, que usa el ApiManager
 * del core; la limitación del arnés impide instanciarlo en el host).
 */
final class CurriculumTermResolver implements TermResolverInterface
{
    /** Tope de candidatos enumerados por dimensión (NFR-004/NFR-008). */
    private const ENUM_LIMIT = 300;

    public function __construct(private CurriculumSearch $search)
    {
    }

    public function listCandidates(string $dimension, array $context = []): array
    {
        return match ($dimension) {
            'etapa' => $this->search->searchEtapas('', self::ENUM_LIMIT),
            'dcterms:relation' => $this->search->searchAxes('', self::ENUM_LIMIT),
            default => $this->search->searchDimension($dimension, '', $context, self::ENUM_LIMIT),
        };
    }

    public function listSubjectFamilies(int $etapaId): array
    {
        return $this->search->searchSubjectFamilies($etapaId, self::ENUM_LIMIT);
    }

    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array
    {
        return $this->search->searchLeaves($dimension, $etapaId, $subjectName, self::ENUM_LIMIT);
    }
}
