<?php

namespace OERManager\Service;

use OERManager\ColumnType\AlignmentStatus;
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
        'licence' => 'dcterms:rights',
        'description' => 'dcterms:description',
        'title' => 'dcterms:title',
        'resource_type' => 'lrmi:learningResourceType',
    ];

    private ApiManager $api;
    private bool $classIdResolved = false;
    private ?int $learningResourceClassId = null;

    public function __construct(ApiManager $api)
    {
        $this->api = $api;
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

        if ('' !== ($query['visibility'] ?? '')) {
            $params['is_public'] = 'public' === $query['visibility'];
        }

        // Filtros que apuntan a un item-término del currículo (resource:item,
        // ADR-0004): el valor esperado es el id de ese item.
        $resourceFilters = [
            'stage' => 'lrmi:educationalLevel',
            'subject' => 'schema:about',
            'project' => 'schema:isPartOf',
            'axis' => 'dcterms:relation',
        ];
        foreach ($resourceFilters as $param => $term) {
            if (!empty($query[$param])) {
                $params['property'][] = [
                    'property' => $term,
                    'type' => 'res',
                    'text' => $query[$param],
                ];
            }
        }

        if (!empty($query['licence'])) {
            $params['property'][] = [
                'property' => 'dcterms:rights',
                'type' => 'eq',
                'text' => $query['licence'],
            ];
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
