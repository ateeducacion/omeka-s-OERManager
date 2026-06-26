<?php

namespace OERManager\Service\Ai;

/**
 * Enumera el conjunto ACOTADO de candidatos de una dimensión del currículo para
 * mostrarlos al LLM (ADR-0007: la IA elige de una lista cerrada y devuelve
 * etiquetas, nunca ids, que son específicos de la instalación). Aísla
 * CurriculumSearch (que necesita el ApiManager del core) para que los
 * clasificadores sean testeables en el host.
 *
 * La acotación contextual (RF-014) viaja en $context: ids de los ancestros ya
 * elegidos (etapa, level=curso, about=asignatura), de modo que Saberes/Criterios
 * solo se enumeran entre los hijos de la Asignatura fijada (cascada top-down).
 * Nunca devuelve el árbol completo: cada dimensión queda acotada por su tipo y su
 * ancestro.
 */
interface TermResolverInterface
{
    /**
     * @param string $dimension 'etapa', 'lrmi:educationalLevel', 'schema:about',
     *   'lrmi:teaches', 'lrmi:assesses' o 'dcterms:relation' (ejes/tags).
     * @param array<string,int|string> $context ids de ancestros ya elegidos.
     *
     * @return array<int,array{id:int,title:string,description:string,block:string}>
     */
    public function listCandidates(string $dimension, array $context = []): array;

    /**
     * Nombres distintos de materia (asignatura) de una etapa (Fase A.2): delimita
     * la materia sin fijar el curso.
     *
     * @return array<int,array{name:string}>
     */
    public function listSubjectFamilies(int $etapaId): array;

    /**
     * Saberes ('lrmi:teaches') o criterios ('lrmi:assesses') de una materia
     * cruzando todos sus cursos (Fase B/C), con linaje para derivar curso+materia.
     *
     * @return array<int,array{id:int,title:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>
     */
    public function listLeaves(string $dimension, int $etapaId, string $subjectName): array;
}
