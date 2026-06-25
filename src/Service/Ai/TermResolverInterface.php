<?php

namespace OERManager\Service\Ai;

/**
 * Resuelve una etiqueta de texto propuesta por el LLM a ids de item-término del
 * currículo (ADR-0007: la IA devuelve etiquetas, nunca ids, que son específicos
 * de la instalación). Aísla CurriculumSearch (que necesita el ApiManager del
 * core) para que los clasificadores sean testeables en el host.
 *
 * La acotación contextual (RF-014) viaja en $context: ids de los ancestros ya
 * elegidos (etapa, level=curso, about=asignatura), de modo que Saberes/Criterios
 * solo se resuelven entre los hijos de la Asignatura fijada.
 */
interface TermResolverInterface
{
    /**
     * @param string $dimension Property RDF de la dimensión ('lrmi:educationalLevel',
     *   'schema:about', 'lrmi:teaches', 'lrmi:assesses', 'dcterms:relation') o
     *   'etapa' para las Etapas (ayuda de navegación, no se escribe en el REA).
     * @param array<string,int|string> $context ids de ancestros ya elegidos.
     *
     * @return array<int,array{id:int,title:string}> candidatos (acotados y limitados).
     */
    public function resolve(string $dimension, string $label, array $context = []): array;
}
