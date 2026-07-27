<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ItemContext;

/**
 * Clasificador de un recurso a partir de su contexto estructurado: devuelve, por
 * property RDF de alineamiento, los ids de item-término propuestos. La IA propone;
 * el curador confirma (ADR-0007). Implementado por CurricularClassifier (cascada
 * jerárquica) y TagClassifier (ejes en un único prompt). El contexto (ADR-0011)
 * permite a cada implementación elegir el texto adecuado a cada paso (grueso/fino).
 */
interface ClassifierInterface
{
    /**
     * @return array<string,int[]> property RDF => ids de item-término propuestos
     *   (solo las dimensiones con propuesta; las vacías se omiten).
     */
    public function classify(ItemContext $context): array;
}
