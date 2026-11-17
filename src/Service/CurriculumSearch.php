<?php

namespace OERManager\Service;

use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Curriculum\StageOrder;
use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * Búsqueda incremental de términos del currículo para el re-catalogador
 * (RF-004/RF-005/RF-014, NFR-004). Nunca carga el árbol completo: toda consulta
 * va acotada al conjunto del currículo, a la clase de la dimensión, a los
 * términos vigentes y a una página; el texto filtra por `skos:prefLabel`.
 *
 * Modelo del currículo: ADR-0022 (`cur:` + SKOS, TASK-064). Las consultas las
 * construye `Curriculum\CurModel`; aquí solo se ejecutan, se combinan y se
 * proyectan a filas para el cliente.
 */
class CurriculumSearch
{
    public const AXIS_SETTING = 'oermanager_axis_termset_item_id';

    /** Property que vincula un DefinedTerm con su DefinedTermSet (ejes). */
    public const IN_TERMSET_TERM = 'schema:inDefinedTermSet';

    private const RESULT_LIMIT = 25;

    private ApiManager $api;
    private Settings $settings;
    private CurriculumTerms $terms;

    public function __construct(ApiManager $api, Settings $settings, CurriculumTerms $terms)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->terms = $terms;
    }

    /**
     * Etapas (`cur:Etapa`) en orden pedagógico. Ayuda de navegación de la
     * cascada; no se escribe en el REA.
     *
     * @return list<array{id:int,title:string,notation:string,description:string,block:string,parentId:int,parentTitle:string}>
     */
    public function searchEtapas(string $text, int $limit = self::RESULT_LIMIT): array
    {
        $query = $this->classQuery(CurModel::ETAPA, $text, $limit);
        if (null === $query) {
            return [];
        }
        return StageOrder::sortStages($this->rows($this->search($query), null));
    }

    /**
     * Términos de una dimensión del REA, acotados por el ancestro elegido más
     * cercano (RF-014, NFR-004). Cardinalidad múltiple (PEND-007): una consulta
     * por id de ancestro, unidas por id, porque el core concatena las filas de
     * `property` sin paréntesis y un OR perdería el resto del filtro.
     *
     * @param array<string,mixed> $context ids de ancestros (escalar o lista):
     *   etapa, level (curso), about (materia en curso)
     * @return list<array{id:int,title:string,notation:string,description:string,block:string,parentId:int,parentTitle:string}>
     */
    public function searchDimension(
        string $dimension,
        string $text,
        array $context = [],
        int $limit = self::RESULT_LIMIT
    ): array {
        $class = CurModel::classOf($dimension);
        $query = null === $class ? null : $this->classQuery($class, $text, $limit);
        if (null === $query) {
            return [];
        }
        $scope = CurModel::scopeFor($dimension, $context);
        $queries = [$query];
        if (null !== $scope) {
            [$edge, $ids] = $scope;
            $queries = array_map(static fn (int $id): array => CurModel::withResource($query, $edge, $id), $ids);
        }
        $items = [];
        foreach ($queries as $one) {
            foreach ($this->search($one) as $item) {
                $items[(int) $item->id()] ??= $item;
            }
        }

        $rows = $this->rows($items, CurModel::parentEdge($dimension));
        if (CurModel::CURSO === $class) {
            $rows = $this->inStageOrder($rows, $items);
        } else {
            usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
        }
        return array_slice($rows, 0, $limit);
    }

    /**
     * Ejes temáticos (tags, dcterms:relation): términos del DefinedTermSet raíz
     * identificado por id de item en la configuración (ADR-0006). No son
     * currículo: no cambian con ADR-0022.
     *
     * @return list<array{id:int,title:string,description:string,block:string,parentId:int,parentTitle:string}>
     */
    public function searchAxes(string $text, int $limit = self::RESULT_LIMIT): array
    {
        $setId = (int) $this->settings->get(self::AXIS_SETTING);
        if ($setId <= 0) {
            return [];
        }
        $query = [
            'property' => [[
                'property' => self::IN_TERMSET_TERM,
                'type' => 'res',
                // 'text' debe ser escalar: buildPropertyQuery hace trim() (un
                // array provoca TypeError fatal en PHP 8.4).
                'text' => (string) $setId,
            ]],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            // `per_page` solo lo aplica el core si viene con `page`.
            'page' => 1,
            'per_page' => $limit,
        ];
        $text = trim($text);
        if ('' !== $text) {
            $query['property'][] = ['property' => 'dcterms:title', 'type' => 'in', 'text' => $text];
        }
        $results = [];
        foreach ($this->search($query) as $item) {
            $results[] = [
                'id' => (int) $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteral($item, 'dcterms:description'),
                'block' => '',
                'parentId' => 0,
                'parentTitle' => '',
            ];
        }
        return $results;
    }

    /**
     * Materias (`cur:Materia`) de una etapa con sus cursos, para la
     * delimitación gruesa de la IA (ADR-0010, ADR-0022 §5). El nombre lleva el
     * sufijo de la etapa («Biología y Geología (ESO)») y es único en ella.
     *
     * @return list<array{name:string,id:int,courses:list<array{id:int,title:string}>}>
     */
    public function searchSubjectFamilies(int $etapaId, int $limit = self::RESULT_LIMIT): array
    {
        $query = $etapaId > 0 ? $this->classQuery(CurModel::MATERIA, '', $limit) : null;
        if (null === $query) {
            return [];
        }
        $families = [];
        foreach ($this->search(CurModel::withResource($query, CurModel::EDGE_ETAPA, $etapaId)) as $item) {
            $name = CurModel::label($item);
            if ('' === $name) {
                continue;
            }
            $families[$name] ??= ['name' => $name, 'id' => (int) $item->id(), 'courses' => []];
            foreach (CurModel::refs($item, CurModel::EDGE_CURSO) as $course) {
                if (!in_array($course, $families[$name]['courses'], true)) {
                    $families[$name]['courses'][] = $course;
                }
            }
        }
        return array_values($families);
    }

    /**
     * Saberes o criterios de una materia en todos sus cursos (o en los dados),
     * con el curso y la materia en curso de cada uno para la derivación
     * bottom-up de la IA (ADR-0010). Acota por etapa, nombre de `cur:Materia` y
     * clase; una consulta por curso, unidas por id (NFR-004).
     *
     * @param int[] $courseIds acota a esos cursos (TASK-056); vacío = todos
     * @return list<array{id:int,title:string,notation:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>
     */
    public function searchLeaves(
        string $dimension,
        int $etapaId,
        string $subjectName,
        int $limit = self::RESULT_LIMIT,
        array $courseIds = []
    ): array {
        $class = CurModel::classOf($dimension);
        $isLeaf = in_array($class, [CurModel::SABER, CurModel::CRITERIO], true);
        if (!$isLeaf || $etapaId <= 0 || '' === trim($subjectName)) {
            return [];
        }
        $query = $this->classQuery($class, '', $limit);
        if (null === $query) {
            return [];
        }
        $query = CurModel::withLabel(
            CurModel::withResource($query, CurModel::EDGE_ETAPA, $etapaId),
            CurModel::EDGE_MATERIA,
            $subjectName
        );
        $queries = [$query];
        $courseIds = self::normalizeContextIds($courseIds);
        if ($courseIds) {
            $queries = array_map(
                static fn (int $id): array => CurModel::withResource($query, CurModel::COURSE_EDGE, $id),
                $courseIds
            );
        }
        $items = [];
        foreach ($queries as $one) {
            foreach ($this->search($one) as $item) {
                $items[(int) $item->id()] ??= $item;
            }
        }

        $results = [];
        foreach ($items as $item) {
            $course = CurModel::ref($item, CurModel::COURSE_EDGE);
            $results[] = [
                'id' => (int) $item->id(),
                'title' => CurModel::label($item),
                'notation' => CurModel::notation($item),
                'description' => CurModel::definition($item),
                'block' => CurModel::block($item),
                'courseId' => $course['id'],
                'courseTitle' => $course['title'],
                'subjectId' => CurModel::ref($item, CurModel::EDGE_MATERIA_CURSO)['id'],
            ];
        }
        return $results;
    }

    /**
     * Ids de ancestro positivos y sin duplicados a partir de un valor de
     * contexto crudo, escalar o lista (la query HTTP los entrega tal cual).
     *
     * @param mixed $value
     * @return list<int>
     */
    public static function normalizeContextIds($value): array
    {
        return CurModel::ids($value);
    }

    /** @return array<string,mixed>|null null when the curriculum or the class is not in this instance */
    private function classQuery(string $class, string $text, int $limit): ?array
    {
        $schemeId = $this->terms->schemeId();
        $classId = $this->terms->classId($class);
        if (null === $schemeId || null === $classId) {
            return null;
        }
        return CurModel::termsQuery($schemeId, $classId, $text, $limit);
    }

    /** @return iterable */
    private function search(array $query): iterable
    {
        return $this->api->search('items', $query)->getContent();
    }

    /**
     * @param iterable $items
     * @return list<array{id:int,title:string,notation:string,description:string,block:string,parentId:int,parentTitle:string}>
     */
    private function rows(iterable $items, ?string $parentEdge): array
    {
        $rows = [];
        foreach ($items as $item) {
            $parent = null === $parentEdge ? ['id' => 0, 'title' => ''] : CurModel::ref($item, $parentEdge);
            $rows[] = [
                'id' => (int) $item->id(),
                'title' => CurModel::label($item),
                'notation' => CurModel::notation($item),
                'description' => CurModel::definition($item),
                'block' => CurModel::block($item),
                'parentId' => $parent['id'],
                'parentTitle' => $parent['title'],
            ];
        }
        return $rows;
    }

    /**
     * Courses by stage (INF, PRI, ESO, BAC) and then by their notation.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int,mixed> $items id → representation
     * @return list<array<string,mixed>>
     */
    private function inStageOrder(array $rows, array $items): array
    {
        foreach ($rows as &$row) {
            $stage = CurModel::target($items[$row['id']], CurModel::EDGE_ETAPA);
            $row['stageNotation'] = $stage ? CurModel::notation($stage) : '';
        }
        unset($row);
        return array_map(static function (array $row): array {
            unset($row['stageNotation']);
            return $row;
        }, StageOrder::sortCourses($rows));
    }

    private function firstLiteral($item, string $term): string
    {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            if ('literal' === $value->type()) {
                return (string) $value->value();
            }
        }
        return '';
    }
}
