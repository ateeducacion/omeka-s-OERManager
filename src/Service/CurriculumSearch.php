<?php

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * Búsqueda incremental de items-término del currículo para el re-catalogador
 * (RF-004/RF-005, NFR-004). Nunca carga el árbol completo: toda consulta lleva
 * el filtro de pertenencia al DefinedTermSet raíz (ADR-0006) y un límite de
 * resultados; el texto acota por título (autocomplete).
 */
class CurriculumSearch
{
    /**
     * Property que vincula un DefinedTerm con su DefinedTermSet.
     * VERIFICAR contra la instalación real antes de confiar en ella (ADR-0006):
     * el modelo de referencia usa schema:inDefinedTermSet.
     */
    public const IN_TERMSET_TERM = 'schema:inDefinedTermSet';

    /** Marco curricular configurable (ADR-0006). */
    public const FRAMEWORK_TERM = 'lrmi:educationalFramework';

    public const AXIS_SETTING = 'oermanager_axis_termset_item_id';
    public const FRAMEWORK_SETTING = 'oermanager_educational_framework';

    private const RESULT_LIMIT = 25;

    private ApiManager $api;
    private Settings $settings;

    public function __construct(ApiManager $api, Settings $settings)
    {
        $this->api = $api;
        $this->settings = $settings;
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
        return $this->searchTermsInSets([$setId], $text);
    }

    /**
     * Alineamiento curricular (educationalLevel/about/teaches/assesses):
     * términos de los DefinedTermSet cuyo lrmi:educationalFramework coincide con
     * el valor configurado (ADR-0006).
     *
     * @return array<int,array{id:int,title:string}>
     */
    public function searchCurricular(string $text): array
    {
        $setIds = $this->resolveCurricularSetIds();
        if (!$setIds) {
            return [];
        }
        return $this->searchTermsInSets($setIds, $text);
    }

    /**
     * @return int[] ids de los DefinedTermSet del marco configurado
     */
    private function resolveCurricularSetIds(): array
    {
        $framework = trim((string) $this->settings->get(self::FRAMEWORK_SETTING));
        if ('' === $framework) {
            return [];
        }
        $response = $this->api->search('items', [
            'property' => [[
                'property' => self::FRAMEWORK_TERM,
                'type' => 'eq',
                'text' => $framework,
            ]],
            'per_page' => 100,
        ]);
        return array_map(
            static fn ($item) => $item->id(),
            $response->getContent()
        );
    }

    /**
     * @param int[] $setIds
     * @return array<int,array{id:int,title:string}>
     */
    private function searchTermsInSets(array $setIds, string $text): array
    {
        // Pertenencia al/los DefinedTermSet raíz: nunca consultamos sin este
        // filtro (NFR-004). 'res' con array de ids => IN (verificar en real).
        $query = [
            'property' => [[
                'property' => self::IN_TERMSET_TERM,
                'type' => 'res',
                'text' => array_values($setIds),
            ]],
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'per_page' => self::RESULT_LIMIT,
        ];
        $text = trim($text);
        if ('' !== $text) {
            $query['property'][] = [
                'property' => 'dcterms:title',
                'type' => 'in',
                'text' => $text,
            ];
        }
        $items = $this->api->search('items', $query)->getContent();
        return array_map(
            static fn ($item) => ['id' => $item->id(), 'title' => (string) $item->displayTitle()],
            $items
        );
    }
}
