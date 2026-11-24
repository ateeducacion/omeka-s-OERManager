<?php

namespace OERManager\Service;

use OERManager\ColumnType\AlignmentStatus;
use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Workflow\WorkflowStatus;
use Omeka\Api\Manager as ApiManager;

/**
 * Traduce los filtros de la vista maestra (ADR-0005 §5) a parámetros de
 * búsqueda de la API de items. La clase lrmi:LearningResource (RF-001) se
 * resuelve por término en runtime, no por id de instalación.
 */
class MasterViewQuery
{
    public const LEARNING_RESOURCE_CLASS_TERM = 'lrmi:LearningResource';

    /**
     * Filtros de gobernanza expresables como query de la API (`nex`), así que no
     * pagan barrido computado. Los afectados hoy, medidos en TASK-027: licencia
     * 18/19, descripción 4/19, tipo 3/19, título 1/19.
     */
    public const MISSING_FILTERS = [
        'licence' => IntegrityPolicy::LICENSE_TERM,
        'description' => 'dcterms:description',
        'title' => 'dcterms:title',
        'resource_type' => 'lrmi:learningResourceType',
    ];

    /**
     * Tope de colecciones que se examinan para ofrecer el filtro: cada una cuesta
     * una consulta de una fila, así que el coste está acotado por esta cifra y no
     * por el tamaño del catálogo (~3000 REA).
     */
    public const MAX_ITEM_SETS = 200;

    /** «Creado hace más de» N days (TASK-070), on `o:created`. Other values are ignored. */
    public const AGE_DAYS = [7, 30, 90];

    /** Filtros que apuntan a un item-término del currículo, y su property (ADR-0004). */
    public const RESOURCE_FILTERS = [
        'stage' => 'lrmi:educationalLevel',
        'subject' => 'schema:about',
        'project' => 'schema:isPartOf',
        'axis' => 'dcterms:relation',
    ];

    /**
     * Filtros de RESOURCE_FILTERS con autocompletado, y la dimensión que entiende
     * `search-terms`. `project` queda fuera: el endpoint no tiene dimensión para él.
     */
    public const SEARCHABLE_FILTER_DIMENSIONS = [
        'stage' => 'lrmi:educationalLevel',
        'subject' => 'schema:about',
        'axis' => 'dcterms:relation',
    ];

    /**
     * «Estado» filter (TASK-067, ADR-0023 §2): key => [query type, literal].
     * `draft` is the absence of a status; `out_of_flow` is a public REA
     * without Publicado (it also sets `is_public`).
     */
    public const STATUS_FILTERS = [
        'draft' => ['nex', null],
        'proposed' => ['eq', WorkflowStatus::PROPOSED],
        'rejected' => ['eq', WorkflowStatus::REJECTED],
        'discarded' => ['eq', WorkflowStatus::DISCARDED],
        'published' => ['eq', WorkflowStatus::PUBLISHED],
        'out_of_flow' => ['neq', WorkflowStatus::PUBLISHED],
    ];

    private ApiManager $api;
    private bool $classIdResolved = false;
    private ?int $learningResourceClassId = null;

    private \Closure $today;

    public function __construct(ApiManager $api, ?\Closure $today = null)
    {
        $this->api = $api;
        $this->today = $today ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('today');
    }

    /**
     * @param array $query Parámetros GET de la página (filtros, orden, paginación)
     * @return array Parámetros para $api->search('items', ...)
     */
    public function buildSearchParams(array $query): array
    {
        $params = [
            'resource_class_id' => $this->resolveLearningResourceClassId(),
        ];

        if (isset($query['page'])) {
            $params['page'] = $query['page'];
        }
        if (!empty($query['sort_by'])) {
            $params['sort_by'] = $query['sort_by'];
        }
        if (!empty($query['sort_order'])) {
            $params['sort_order'] = $query['sort_order'];
        }

        if (!empty($query['title'])) {
            $params['property'][] = [
                'property' => 'dcterms:title',
                'type' => 'in',
                'text' => $query['title'],
            ];
        }

        $itemSetId = (int) ($query['item_set_id'] ?? 0);
        if ($itemSetId > 0) {
            $params['item_set_id'] = $itemSetId;
        }

        if ('' !== ($query['visibility'] ?? '')) {
            $params['is_public'] = 'public' === $query['visibility'];
        }

        // TASK-070: age on the catalogue entry date; the core compares `created < day`.
        $age = $query['age'] ?? null;
        if (is_string($age) && ctype_digit($age) && in_array((int) $age, self::AGE_DAYS, true)) {
            $params['created_before'] = ($this->today)()
                ->modify(sprintf('-%d days', (int) $age))
                ->format('Y-m-d');
        }

        // Filtros que apuntan a un item-término del currículo (resource:item,
        // ADR-0004): el valor esperado es el id de ese item.
        foreach (self::RESOURCE_FILTERS as $param => $term) {
            if (!empty($query[$param])) {
                $params['property'][] = [
                    'property' => $term,
                    'type' => 'res',
                    'text' => $query[$param],
                ];
            }
        }

        // `eq` del core casa contra `value` O `uri` (buildPropertyQuery), así que
        // el filtro admite la etiqueta exacta o la URI exacta (ADR-0019).
        if (!empty($query['licence'])) {
            $params['property'][] = [
                'property' => IntegrityPolicy::LICENSE_TERM,
                'type' => 'eq',
                'text' => $query['licence'],
            ];
        }

        // «Estado» (TASK-067): `proposed=1` is the older «Propuestos» checkbox.
        $status = $query['status'] ?? (empty($query['proposed']) ? '' : 'proposed');
        if (is_string($status) && isset(self::STATUS_FILTERS[$status])) {
            [$type, $literal] = self::STATUS_FILTERS[$status];
            $row = ['property' => WorkflowStatus::STATUS_TERM, 'type' => $type];
            if (null !== $literal) {
                $row['text'] = $literal;
            }
            $params['property'][] = $row;
            if ('out_of_flow' === $status) {
                $params['is_public'] = true;
            }
        }

        // D1: lrmi:learningResourceType son literales del CustomVocab, no
        // enlaces a item. Con operador `res` este filtro no podía casar nunca.
        if (!empty($query['resource_type'])) {
            $params['property'][] = [
                'property' => 'lrmi:learningResourceType',
                'type' => 'eq',
                'text' => $query['resource_type'],
            ];
        }

        // Filtros «sin X» de gobernanza. Se piden como missing[]=clave para no
        // colisionar con los filtros de valor: `licence` filtra POR licencia y
        // `missing[]=licence` filtra por su AUSENCIA.
        foreach ((array) ($query['missing'] ?? []) as $key) {
            if (is_string($key) && isset(self::MISSING_FILTERS[$key])) {
                $params['property'][] = [
                    'property' => self::MISSING_FILTERS[$key],
                    'type' => 'nex',
                ];
            }
        }

        $this->addAlignmentFilter($params, $query['alignment'] ?? '');

        return $params;
    }

    /**
     * Colecciones que contienen al menos un REA, para el selector de la búsqueda
     * avanzada (RF-018). Una consulta de una fila por colección, con tope.
     *
     * @return array<int,string> id => título
     */
    public function learningResourceItemSets(): array
    {
        $classId = $this->resolveLearningResourceClassId();
        if (null === $classId) {
            return [];
        }

        $sets = $this->api->search('item_sets', [
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'limit' => self::MAX_ITEM_SETS,
        ])->getContent();

        $offered = [];
        foreach ($sets as $set) {
            $probe = $this->api->search('items', [
                'item_set_id' => $set->id(),
                'resource_class_id' => $classId,
                'limit' => 1,
            ]);
            if ($probe->getTotalResults() > 0) {
                $offered[$set->id()] = (string) $set->displayTitle();
            }
        }
        return $offered;
    }

    /**
     * El adaptador de Omeka no soporta agrupar condiciones AND/OR con
     * paréntesis, así que la query solo puede acotar a "no sin alinear" (ver
     * addAlignmentFilter()); excluir "completo" es un filtro computado y lo
     * resuelve el controlador con ComputedFilter (ADR-0013, D4). Antes se
     * cribaba aquí la página ya paginada, que daba un total aproximado.
     */
    private function addAlignmentFilter(array &$params, string $alignment): void
    {
        if (AlignmentStatus::NONE === $alignment) {
            $params['property'][] = ['property' => 'lrmi:assesses', 'type' => 'nex'];
            $params['property'][] = ['property' => 'lrmi:teaches', 'type' => 'nex', 'joiner' => 'and'];
            return;
        }
        if (AlignmentStatus::COMPLETE === $alignment) {
            $params['property'][] = ['property' => 'lrmi:educationalLevel', 'type' => 'ex'];
            $params['property'][] = ['property' => 'schema:about', 'type' => 'ex', 'joiner' => 'and'];
            $params['property'][] = ['property' => 'lrmi:assesses', 'type' => 'ex', 'joiner' => 'and'];
            $params['property'][] = ['property' => 'lrmi:teaches', 'type' => 'ex', 'joiner' => 'and'];
            return;
        }
        if (AlignmentStatus::PARTIAL === $alignment) {
            // Acota a "no sin alinear" (tiene criterio o saber); el resto del
            // cálculo lo hace filterPartialAlignment() sobre la página.
            $params['property'][] = ['property' => 'lrmi:assesses', 'type' => 'ex'];
            $params['property'][] = ['property' => 'lrmi:teaches', 'type' => 'ex', 'joiner' => 'or'];
        }
    }

    private function resolveLearningResourceClassId(): ?int
    {
        if (!$this->classIdResolved) {
            $response = $this->api
                ->search('resource_classes', ['term' => self::LEARNING_RESOURCE_CLASS_TERM])
                ->getContent();
            $this->learningResourceClassId = $response ? $response[0]->id() : null;
            $this->classIdResolved = true;
        }
        return $this->learningResourceClassId;
    }
}
