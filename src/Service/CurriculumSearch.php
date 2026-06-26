<?php

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * Búsqueda incremental de items-término del currículo para el re-catalogador
 * (RF-004/RF-005/RF-014, NFR-004). Nunca carga el árbol completo: toda consulta
 * lleva un filtro acotador (dcterms:type por dimensión + contexto del ancestro)
 * y un límite; el texto acota por título (autocomplete).
 *
 * Modelo del grafo curricular en ADR-0009 y docs/referencia/curriculo-modelo-rdf.md:
 * la dimensión se distingue por dcterms:type (literal) y los nodos se enlazan a
 * su padre (Etapa←Curso←Asignatura←{Saber, Criterio}) y a sus ancestros con
 * properties denormalizadas, lo que permite la acotación contextual.
 */
class CurriculumSearch
{
    /** Property que distingue la dimensión de un término. */
    public const TYPE_TERM = 'dcterms:type';

    /** Marco curricular configurable (ADR-0006); raíz de las Etapas. */
    public const FRAMEWORK_TERM = 'lrmi:educationalFramework';

    public const AXIS_SETTING = 'oermanager_axis_termset_item_id';
    public const FRAMEWORK_SETTING = 'oermanager_educational_framework';

    /** Property que vincula un DefinedTerm con su DefinedTermSet (ejes). */
    public const IN_TERMSET_TERM = 'schema:inDefinedTermSet';

    /**
     * Valor de dcterms:type por dimensión curricular (setting del módulo).
     * Específico de la instalación: lo fija el propietario en la config.
     */
    public const TYPE_SETTINGS = [
        'lrmi:educationalLevel' => 'oermanager_type_educationallevel',
        'schema:about' => 'oermanager_type_about',
        'lrmi:teaches' => 'oermanager_type_teaches',
        'lrmi:assesses' => 'oermanager_type_assesses',
    ];

    private const RESULT_LIMIT = 25;

    private ApiManager $api;
    private Settings $settings;

    public function __construct(ApiManager $api, Settings $settings)
    {
        $this->api = $api;
        $this->settings = $settings;
    }

    /**
     * Etapas educativas: los DefinedTermSet del marco configurado (ADR-0006).
     * Ayuda de navegación de la cascada; no se escribe en el REA.
     *
     * @return array<int,array{id:int,title:string}>
     */
    public function searchEtapas(string $text, int $limit = self::RESULT_LIMIT): array
    {
        $framework = trim((string) $this->settings->get(self::FRAMEWORK_SETTING));
        if ('' === $framework) {
            return [];
        }
        $query = [
            'property' => [[
                'property' => self::FRAMEWORK_TERM,
                'type' => 'eq',
                'text' => $framework,
            ]],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => $limit,
        ];
        $this->addTitleFilter($query, $text);
        return $this->mapResults($this->api->search('items', $query)->getContent());
    }

    /**
     * Términos de una dimensión curricular, acotados por su dcterms:type y por
     * el ancestro ya elegido (contexto), si lo hay (RF-014, NFR-004).
     *
     * @param array<string,int|string> $context ids de ancestros: etapa, level (curso), about (asignatura)
     * @return array<int,array{id:int,title:string}>
     */
    public function searchDimension(
        string $dimension,
        string $text,
        array $context = [],
        int $limit = self::RESULT_LIMIT
    ): array {
        if (!isset(self::TYPE_SETTINGS[$dimension])) {
            return [];
        }
        $typeValue = trim((string) $this->settings->get(self::TYPE_SETTINGS[$dimension]));
        if ('' === $typeValue) {
            return [];
        }
        // dcterms:type se trata como literal (eq): confirmado contra la
        // instalación real (2026-06-24). Mejora propuesta (RF-013): migrar estos
        // literales a recursos skos:Concept; entonces el filtro pasaría a 'res'.
        $query = [
            'property' => [[
                'property' => self::TYPE_TERM,
                'type' => 'eq',
                'text' => $typeValue,
            ]],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => $limit,
        ];
        $contextFilter = $this->contextFilter($dimension, $context);
        if (null !== $contextFilter) {
            $query['property'][] = [
                'property' => $contextFilter[0],
                'type' => 'res',
                'text' => (string) $contextFilter[1],
            ];
        }
        $this->addTitleFilter($query, $text);
        return $this->mapResults($this->api->search('items', $query)->getContent());
    }

    /**
     * Ejes temáticos (tags, dcterms:relation): términos del DefinedTermSet raíz
     * identificado por id de item en la configuración (ADR-0006).
     *
     * @return array<int,array{id:int,title:string}>
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
            'per_page' => $limit,
        ];
        $this->addTitleFilter($query, $text);
        return $this->mapResults($this->api->search('items', $query)->getContent());
    }

    /**
     * Nombres distintos de materia (asignatura) de una etapa, para la
     * delimitación gruesa de la IA (Fase A.2). No fija curso: agrupa por nombre
     * canónico (schema:about, fallback título). Acota por etapa + tipo (NFR-004).
     *
     * @return array<int,array{name:string}>
     */
    public function searchSubjectFamilies(int $etapaId, int $limit = self::RESULT_LIMIT): array
    {
        if ($etapaId <= 0) {
            return [];
        }
        $typeValue = trim((string) $this->settings->get(self::TYPE_SETTINGS['schema:about']));
        if ('' === $typeValue) {
            return [];
        }
        $query = [
            'property' => [
                ['property' => self::TYPE_TERM, 'type' => 'eq', 'text' => $typeValue],
                ['property' => 'dcterms:isPartOf', 'type' => 'res', 'text' => (string) $etapaId],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => $limit,
        ];
        $names = [];
        foreach ($this->api->search('items', $query)->getContent() as $item) {
            $name = $this->firstLiteralValue($item, 'schema:about');
            if ('' === $name) {
                $name = trim((string) $item->displayTitle());
            }
            if ('' !== $name) {
                $names[$name] = true;
            }
        }
        return array_map(static fn (string $n): array => ['name' => $n], array_keys($names));
    }

    /**
     * Saberes/criterios de una MATERIA cruzando todos sus cursos (Fase B/C), con
     * linaje (courseId/subjectId) para la derivación bottom-up (Fase D). Acota por
     * etapa + nombre de materia (schema:about) + tipo; nunca carga el árbol
     * completo (NFR-004).
     *
     * @return array<int,array{id:int,title:string,description:string,block:string,courseId:int,courseTitle:string,subjectId:int}>
     */
    public function searchLeaves(
        string $dimension,
        int $etapaId,
        string $subjectName,
        int $limit = self::RESULT_LIMIT
    ): array {
        if (!isset(self::TYPE_SETTINGS[$dimension]) || $etapaId <= 0 || '' === trim($subjectName)) {
            return [];
        }
        $typeValue = trim((string) $this->settings->get(self::TYPE_SETTINGS[$dimension]));
        if ('' === $typeValue) {
            return [];
        }
        $query = [
            'property' => [
                ['property' => self::TYPE_TERM, 'type' => 'eq', 'text' => $typeValue],
                ['property' => 'schema:about', 'type' => 'eq', 'text' => trim($subjectName)],
                ['property' => 'dcterms:isPartOf', 'type' => 'res', 'text' => (string) $etapaId],
            ],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => $limit,
        ];
        $results = [];
        foreach ($this->api->search('items', $query)->getContent() as $item) {
            $course = $this->firstResourceRef($item, 'lrmi:educationalAlignment');
            $results[] = [
                'id' => (int) $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteralValue($item, 'dcterms:description'),
                'block' => $this->firstLiteralValue($item, 'dcterms:subject'),
                'courseId' => $course['id'],
                'courseTitle' => $course['title'],
                'subjectId' => $this->resourceRefMatchingTitle($item, self::IN_TERMSET_TERM, $subjectName),
            ];
        }
        return $results;
    }

    /**
     * Filtro de pertenencia al ancestro elegido (ADR-0009 §3-5). Devuelve
     * [property_term, ancestorId] o null si no hay contexto aplicable.
     *
     * @param array<string,int|string> $context
     * @return array{0:string,1:int}|null
     */
    private function contextFilter(string $dimension, array $context): ?array
    {
        $etapa = (int) ($context['etapa'] ?? 0);
        $curso = (int) ($context['level'] ?? 0);
        $asignatura = (int) ($context['about'] ?? 0);

        switch ($dimension) {
            case 'lrmi:educationalLevel': // Curso → por Etapa
                if ($etapa > 0) {
                    return [self::IN_TERMSET_TERM, $etapa];
                }
                break;
            case 'schema:about': // Asignatura → por Curso
                if ($curso > 0) {
                    return ['lrmi:educationalLevel', $curso];
                }
                break;
            case 'lrmi:teaches': // Saber → por Asignatura, fallback Curso/Etapa
            case 'lrmi:assesses': // Criterio → ídem
                if ($asignatura > 0) {
                    return [self::IN_TERMSET_TERM, $asignatura];
                }
                if ($curso > 0) {
                    return ['lrmi:educationalAlignment', $curso];
                }
                if ($etapa > 0) {
                    return ['dcterms:isPartOf', $etapa];
                }
                break;
        }
        return null;
    }

    private function addTitleFilter(array &$query, string $text): void
    {
        $text = trim($text);
        if ('' !== $text) {
            $query['property'][] = [
                'property' => 'dcterms:title',
                'type' => 'in',
                'text' => $text,
            ];
        }
    }

    private function firstLiteralValue($item, string $term): string
    {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $v) {
            if ('literal' === $v->type()) {
                return (string) $v->value();
            }
        }
        return '';
    }

    /** @return array{id:int,title:string} */
    private function firstResourceRef($item, string $term): array
    {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $v) {
            $res = $v->valueResource();
            if (null !== $res) {
                return ['id' => (int) $res->id(), 'title' => (string) $res->displayTitle()];
            }
        }
        return ['id' => 0, 'title' => ''];
    }

    /**
     * De las referencias resource de $term, el id cuyo título coincide con $title.
     * Para un criterio, schema:inDefinedTermSet apunta a {Competencia, Asignatura};
     * la Asignatura es la que tiene por título el nombre de la materia (la
     * Competencia es un código). Fallback: primera referencia.
     */
    private function resourceRefMatchingTitle($item, string $term, string $title): int
    {
        $first = 0;
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $v) {
            $res = $v->valueResource();
            if (null === $res) {
                continue;
            }
            $rid = (int) $res->id();
            if (0 === $first) {
                $first = $rid;
            }
            if (trim((string) $res->displayTitle()) === trim($title)) {
                return $rid;
            }
        }
        return $first;
    }

    /**
     * @param iterable $items
     * @return array<int,array{id:int,title:string,description:string,block:string}>
     */
    private function mapResults($items): array
    {
        $results = [];
        foreach ($items as $item) {
            $results[] = [
                'id' => $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteralValue($item, 'dcterms:description'),
                'block' => $this->firstLiteralValue($item, 'dcterms:subject'),
            ];
        }
        return $results;
    }
}
