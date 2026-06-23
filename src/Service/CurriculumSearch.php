<?php

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * Búsqueda incremental de items-término del currículo para el re-catalogador
 * (RF-004/RF-005, NFR-004). Nunca carga el árbol completo: toda consulta lleva
 * un filtro acotador (dcterms:type por dimensión, o pertenencia al set de ejes)
 * y un límite de resultados; el texto acota por título (autocomplete).
 *
 * Dentro de un marco (lrmi:educationalFramework, ADR-0006) todos los términos
 * conviven en los mismos DefinedTermSet, así que la dimensión NO se distingue
 * por set sino por dcterms:type (modelo real confirmado 2026-06-23, ver
 * docs/referencia/curriculo-modelo-rdf.md). Cada input curricular ofrece solo
 * los términos de su dcterms:type, configurado por el propietario.
 */
class CurriculumSearch
{
    /** Property que distingue la dimensión de un término. */
    public const TYPE_TERM = 'dcterms:type';

    /** Property que vincula un DefinedTerm con su DefinedTermSet (ejes). */
    public const IN_TERMSET_TERM = 'schema:inDefinedTermSet';

    /** Marco curricular configurable (ADR-0006); referencia/validación. */
    public const FRAMEWORK_TERM = 'lrmi:educationalFramework';

    public const AXIS_SETTING = 'oermanager_axis_termset_item_id';
    public const FRAMEWORK_SETTING = 'oermanager_educational_framework';

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
     * Términos de una dimensión curricular, acotados por su dcterms:type
     * (NFR-004: filtro acotador + límite + texto por título).
     *
     * @return array<int,array{id:int,title:string}>
     */
    public function searchDimension(string $dimension, string $text): array
    {
        if (!isset(self::TYPE_SETTINGS[$dimension])) {
            return [];
        }
        $typeValue = trim((string) $this->settings->get(self::TYPE_SETTINGS[$dimension]));
        if ('' === $typeValue) {
            return [];
        }
        // dcterms:type por defecto se trata como literal (eq). VERIFICAR en
        // contenedor si fuera resource (cambiaría a 'res' con el id del tipo).
        $query = [
            'property' => [[
                'property' => self::TYPE_TERM,
                'type' => 'eq',
                'text' => $typeValue,
            ]],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => self::RESULT_LIMIT,
        ];
        $this->addTitleFilter($query, $text);
        return $this->mapResults($this->api->search('items', $query)->getContent());
    }

    /**
     * Ejes temáticos (tags, dcterms:relation): términos del DefinedTermSet raíz
     * identificado por id de item en la configuración (ADR-0006).
     *
     * @return array<int,array{id:int,title:string}>
     */
    public function searchAxes(string $text): array
    {
        $setId = (int) $this->settings->get(self::AXIS_SETTING);
        if ($setId <= 0) {
            return [];
        }
        // Pertenencia al DefinedTermSet de ejes: nunca sin este filtro (NFR-004).
        $query = [
            'property' => [[
                'property' => self::IN_TERMSET_TERM,
                'type' => 'res',
                'text' => [$setId],
            ]],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => self::RESULT_LIMIT,
        ];
        $this->addTitleFilter($query, $text);
        return $this->mapResults($this->api->search('items', $query)->getContent());
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

    /**
     * @param iterable $items
     * @return array<int,array{id:int,title:string}>
     */
    private function mapResults($items): array
    {
        $results = [];
        foreach ($items as $item) {
            $results[] = ['id' => $item->id(), 'title' => (string) $item->displayTitle()];
        }
        return $results;
    }
}
