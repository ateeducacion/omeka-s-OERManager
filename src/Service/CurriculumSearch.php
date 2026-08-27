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

    /**
     * Property por la que cada dimensión cuelga de su ancestro (ADR-0009). Es
     * el inverso del filtro de contexto: aquí no se acota, se muestra el linaje
     * para desambiguar homónimos («Matemáticas» aparece 7 veces con distinto
     * curso). Etapas y ejes no tienen padre que mostrar.
     */
    private const PARENT_TERMS = [
        'lrmi:educationalLevel' => self::IN_TERMSET_TERM,
        'schema:about' => 'lrmi:educationalLevel',
        'lrmi:teaches' => 'lrmi:educationalAlignment',
        'lrmi:assesses' => 'lrmi:educationalAlignment',
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
     * @return array<int,array{id:int,title:string,description:string,block:string,parentId:int,parentTitle:string}>
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
            // `per_page` solo lo aplica el core si viene con `page`
            // (AbstractEntityAdapter::limitQuery): sin esto el límite se
            // ignoraba y el autocompletado traía el árbol entero (NFR-004).
            'page' => 1,
            'per_page' => $limit,
        ];
        $this->addTitleFilter($query, $text);
        return $this->mapResults($this->api->search('items', $query)->getContent());
    }

    /**
     * Términos de una dimensión curricular, acotados por su dcterms:type y por
     * el ancestro ya elegido (contexto), si lo hay (RF-014, NFR-004).
     *
     * @param array<string,mixed> $context ids de ancestros (escalar o lista): etapa, level (curso), about (asignatura)
     * @return array<int,array{id:int,title:string,description:string,block:string,parentId:int,parentTitle:string}>
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
            // `per_page` solo lo aplica el core si viene con `page`
            // (AbstractEntityAdapter::limitQuery): sin esto el límite se
            // ignoraba y el autocompletado traía el árbol entero (NFR-004).
            'page' => 1,
            'per_page' => $limit,
        ];
        $this->addTitleFilter($query, $text);

        $contextFilter = $this->contextFilter($dimension, $context);
        if (null === $contextFilter) {
            return $this->mapResults(
                $this->api->search('items', $query)->getContent(),
                self::PARENT_TERMS[$dimension] ?? null
            );
        }

        // Cardinalidad múltiple es regla de negocio (PEND-007): un REA puede
        // llevar más de un Curso, y entonces Materia debe acotar por TODOS,
        // no por el primero. `res` solo compara igualdad contra UN id, y
        // encadenar varias filas 'res' con joiner 'or' no sirve:
        // `buildPropertyQuery` concatena el WHERE como texto plano sin
        // paréntesis entre filas, así que "tipo AND ctx1 OR ctx2" se lee
        // "(tipo AND ctx1) OR ctx2" y no "tipo AND (ctx1 OR ctx2)" — perdería
        // el filtro de tipo para el segundo ancestro en adelante. Se lanza una
        // consulta por ancestro (acotada igual que antes) y se combinan aquí.
        [$propertyTerm, $ancestorIds] = $contextFilter;
        $merged = [];
        foreach ($ancestorIds as $ancestorId) {
            $scoped = $query;
            $scoped['property'][] = [
                'property' => $propertyTerm,
                'type' => 'res',
                'text' => (string) $ancestorId,
            ];
            foreach ($this->api->search('items', $scoped)->getContent() as $item) {
                $merged[(int) $item->id()] = $item;
            }
        }
        usort(
            $merged,
            static fn ($a, $b): int => strnatcasecmp((string) $a->displayTitle(), (string) $b->displayTitle())
        );

        return $this->mapResults(array_slice($merged, 0, $limit), self::PARENT_TERMS[$dimension] ?? null);
    }

    /**
     * Ejes temáticos (tags, dcterms:relation): términos del DefinedTermSet raíz
     * identificado por id de item en la configuración (ADR-0006).
     *
     * @return array<int,array{id:int,title:string,description:string,block:string,parentId:int,parentTitle:string}>
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
            // `per_page` solo lo aplica el core si viene con `page`
            // (AbstractEntityAdapter::limitQuery): sin esto el límite se
            // ignoraba y el autocompletado traía el árbol entero (NFR-004).
            'page' => 1,
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
            // `per_page` solo lo aplica el core si viene con `page`
            // (AbstractEntityAdapter::limitQuery): sin esto el límite se
            // ignoraba y el autocompletado traía el árbol entero (NFR-004).
            'page' => 1,
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
            // `per_page` solo lo aplica el core si viene con `page`
            // (AbstractEntityAdapter::limitQuery): sin esto el límite se
            // ignoraba y el autocompletado traía el árbol entero (NFR-004).
            'page' => 1,
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
     * Ids de ancestro positivos y sin duplicados a partir de un valor de
     * contexto crudo, que puede llegar escalar (una sola selección) o lista
     * (varias): la query HTTP los entrega tal cual el cliente los mandó, sin
     * normalizar. Pura y sin dependencias del core: se prueba en el host.
     *
     * @param mixed $value
     * @return list<int>
     */
    public static function normalizeContextIds($value): array
    {
        $ids = [];
        foreach ((array) $value as $raw) {
            $id = (int) $raw;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Filtro de pertenencia a los ancestros elegidos (ADR-0009 §3-5). Devuelve
     * [property_term, list<int> $ancestorIds] o null si no hay contexto
     * aplicable. Cardinalidad múltiple (PEND-007): puede haber más de un id
     * por dimensión de ancestro.
     *
     * @param array<string,mixed> $context
     * @return array{0:string,1:list<int>}|null
     */
    private function contextFilter(string $dimension, array $context): ?array
    {
        $etapaIds = self::normalizeContextIds($context['etapa'] ?? null);
        $cursoIds = self::normalizeContextIds($context['level'] ?? null);
        $asignaturaIds = self::normalizeContextIds($context['about'] ?? null);

        switch ($dimension) {
            case 'lrmi:educationalLevel': // Curso → por Etapa
                if ($etapaIds) {
                    return [self::IN_TERMSET_TERM, $etapaIds];
                }
                break;
            case 'schema:about': // Asignatura → por Curso
                if ($cursoIds) {
                    return ['lrmi:educationalLevel', $cursoIds];
                }
                break;
            case 'lrmi:teaches': // Saber → por Asignatura, fallback Curso/Etapa
            case 'lrmi:assesses': // Criterio → ídem
                if ($asignaturaIds) {
                    return [self::IN_TERMSET_TERM, $asignaturaIds];
                }
                if ($cursoIds) {
                    return ['lrmi:educationalAlignment', $cursoIds];
                }
                if ($etapaIds) {
                    return ['dcterms:isPartOf', $etapaIds];
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
     * @return array<int,array{id:int,title:string,description:string,block:string,parentId:int,parentTitle:string}>
     */
    private function mapResults($items, ?string $parentTerm = null): array
    {
        $results = [];
        foreach ($items as $item) {
            $parent = $parentTerm ? $this->firstResourceRef($item, $parentTerm) : ['id' => 0, 'title' => ''];
            $results[] = [
                'id' => (int) $item->id(),
                'title' => (string) $item->displayTitle(),
                'description' => $this->firstLiteralValue($item, 'dcterms:description'),
                'block' => $this->firstLiteralValue($item, 'dcterms:subject'),
                'parentId' => (int) $parent['id'],
                'parentTitle' => (string) $parent['title'],
            ];
        }
        return $results;
    }
}
