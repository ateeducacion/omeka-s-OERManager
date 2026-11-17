<?php

namespace OERManager\Service\Ai;

use OERManager\Service\CurriculumSearch;

/**
 * Adapta CurriculumSearch al puerto TermResolverInterface sobre el modelo `cur:`
 * (ADR-0022, TASK-064 rebanada 6): enumera el conjunto acotado de candidatos de
 * cada dimensión para mostrárselos al LLM. Usa un límite de enumeración mayor
 * que el de autocompletar (cubre los ejes y las hojas de una materia) pero
 * acotado para no disparar el coste de tokens.
 *
 * - Familias: las `cur:Materia` de la etapa, con su id y sus cursos.
 * - Hojas: `cur:SaberBasico` o `cur:CriterioEvaluacion` de la etapa, la materia
 *   (`cur:materia`, por nombre: único en la etapa) y los cursos (`cur:curso`);
 *   texto = `skos:definition`; bloque = `cur:BloqueSaberes` o
 *   `cur:BloqueCompetencial`.
 * - El código que ve el modelo es la `skos:notation`, como el título-código de
 *   ADR-0009: la `skos:prefLabel` («<notación> · <texto truncado…>») repetiría
 *   la definición en cada línea («texto (código)», PromptBuilder).
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

    public function listLeaves(string $dimension, int $etapaId, string $subjectName, array $courseIds = []): array
    {
        return array_map(static function (array $leaf): array {
            $notation = trim((string) ($leaf['notation'] ?? ''));
            if ('' !== $notation) {
                $leaf['title'] = $notation;
            }
            return $leaf;
        }, $this->search->searchLeaves($dimension, $etapaId, $subjectName, self::ENUM_LIMIT, $courseIds));
    }
}
