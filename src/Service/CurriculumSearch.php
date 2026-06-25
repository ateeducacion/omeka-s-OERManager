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
