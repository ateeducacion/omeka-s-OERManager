<?php

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;

/**
 * Escritura del alineamiento curricular y los ejes temáticos como resource
 * values RDF (RF-004/RF-005, ADR-0004), con preview del diff, validación de
 * destino y auditoría reversible vía value annotations dcterms (ADR-0002).
 *
 * Patrón obligatorio (skill recatalogador): preview + confirmación + auditoría.
 * Properties resueltas SIEMPRE por término, nunca por property_id hardcodeado.
 * Proyecto (schema:isPartOf) queda fuera: es acción de gestor aparte (ADR-0004).
 */
class RecatalogService
{
    /** Properties que el re-catalogador puede escribir (ADR-0004). */
    public const ALIGNMENT_TERMS = [
        'lrmi:educationalLevel',
        'schema:about',
        'lrmi:teaches',
        'lrmi:assesses',
        'dcterms:relation',
    ];

    /** Dimensiones cuyo valor puede llevar justificación de la IA (TASK-023). */
    private const JUSTIFIABLE_TERMS = ['lrmi:teaches', 'lrmi:assesses'];

    /**
     * Aristas al ancestro que desambigua un item-término (D7), en orden de
     * preferencia: el Curso cuando existe —Asignatura vía lrmi:educationalLevel,
     * Saber/Criterio vía la denormalizada lrmi:educationalAlignment (ADR-0009)— y,
     * si no, el conjunto al que pertenece (Curso→Etapa, Eje→su DefinedTermSet).
     */
    private const QUALIFIER_TERMS = [
        'lrmi:educationalLevel',
        'lrmi:educationalAlignment',
        'schema:inDefinedTermSet',
    ];

    /**
     * Dimensión que NO se califica: los ejes son un vocabulario plano y todos
     * cuelgan del mismo DefinedTermSet, así que el sufijo sería constante («…
     * (Categorías de REAs)») y alargaría cada línea sin desambiguar nada.
     */
    private const UNQUALIFIED_TERM = 'dcterms:relation';

    /** Tope de longitud de la justificación anotada (defensa en profundidad). */
    private const MAX_REASON_CHARS = 200;

    /** @var array<string,int|null> caché term => property_id */
    private array $propertyIds = [];

    private ApiManager $api;
    private Settings $settings;

    public function __construct(ApiManager $api, Settings $settings)
    {
        $this->api = $api;
        $this->settings = $settings;
    }

    /**
     * Diff entre el alineamiento actual y el propuesto, sin escribir nada.
     *
     * @param array<string,array<int|string>> $proposed term => ids de item-término
     * @return array<string,array{current:int[],next:int[],added:int[],removed:int[],invalid:int[],titles:array<int,string>}>
     */
    public function preview(int $itemId, array $proposed): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $diff = [];
        foreach (self::ALIGNMENT_TERMS as $term) {
            if (!array_key_exists($term, $proposed)) {
                continue;
            }
            $currentTargets = $this->currentTargets($item, $term);
            $current = $currentTargets['ids'];
            $titles = $currentTargets['titles'];
            $next = $this->normalizeIds($proposed[$term]);
            $invalid = $this->invalidTargets($term, $next, $titles);
            $diff[$term] = [
                'current' => $current,
                'next' => $next,
                'added' => array_values(array_diff($next, $current)),
                'removed' => array_values(array_diff($current, $next)),
                'invalid' => $invalid,
                // D7: el cliente necesita títulos para que el curador confirme
                // viendo QUÉ cambia, no solo cuántos.
                'titles' => $titles,
            ];
        }
        return $diff;
    }

    /**
     * Escribe el alineamiento propuesto (cardinalidad múltiple, RF-004) con
     * auditoría dcterms sobre cada valor. Lanza si algún destino no existe.
     *
     * @param array<string,array<int|string>> $proposed
     * @param array<string,array<int,string>> $justifications term => {itemId => texto}
     *   justificación IA por saber/criterio (TASK-023); se anota como dcterms:description
     * @return array{updated:bool,properties:string[]}
     */
    public function apply(int $itemId, array $proposed, string $contributor, array $justifications = []): array
    {
        $now = (new \DateTimeImmutable())->format('c');
        $data = [];
        $clear = [];
        $properties = [];
        foreach (self::ALIGNMENT_TERMS as $term) {
            if (!array_key_exists($term, $proposed)) {
                continue;
            }
            $propertyId = $this->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            $ids = $this->normalizeIds($proposed[$term]);
            $invalid = $this->invalidTargets($term, $ids);
            if ($invalid) {
                throw new \RuntimeException(sprintf(
                    'Destinos inválidos para %s (inexistentes o de otra dimensión): %s',
                    $term,
                    implode(', ', $invalid)
                ));
            }
            // Limpiar SOLO esta property (clear_property_values) y anexar sus
            // nuevos valores. Vaciar una dimensión = limpiarla sin anexar.
            $clear[] = $propertyId;
            $properties[] = $term;
            if ($ids) {
                $data[$term] = $this->buildValues(
                    $propertyId,
                    $ids,
                    $contributor,
                    $now,
                    $term,
                    $justifications[$term] ?? []
                );
            }
        }
        if (!$clear) {
            return ['updated' => false, 'properties' => []];
        }
        // El partial de Omeka reemplaza el set COMPLETO de values del item
        // (ValueHydrator recorre la colección plana y borra lo no reutilizado).
        // Para tocar SOLO las properties editadas: limpiar esas properties con
        // clear_property_values y anexar (collectionAction=append) los nuevos
        // valores; en modo append Omeka no reutiliza ni borra el resto, así que
        // título, descripción, licencia, proyecto, etc. quedan intactos.
        $data['clear_property_values'] = $clear;
        $this->api->update(
            'items',
            $itemId,
            $data,
            [],
            ['isPartial' => true, 'collectionAction' => 'append']
        );
        return ['updated' => true, 'properties' => $properties];
    }

    /**
     * @param int[] $ids
     * @param array<int,string> $justForTerm justificación IA por itemId (TASK-023)
     * @return array<int,array<string,mixed>>
     */
    private function buildValues(
        int $propertyId,
        array $ids,
        string $contributor,
        string $when,
        string $term,
        array $justForTerm = []
    ): array {
        $justifiable = in_array($term, self::JUSTIFIABLE_TERMS, true);
        $values = [];
        foreach ($ids as $targetId) {
            $reason = $justifiable ? trim((string) ($justForTerm[$targetId] ?? '')) : '';
            $values[] = [
                'type' => 'resource:item',
                'property_id' => $propertyId,
                'value_resource_id' => $targetId,
                '@annotation' => $this->annotation($contributor, $when, $term, $reason),
            ];
        }
        return $values;
    }

    /**
     * Auditoría RDF nativa (ADR-0002): quién/cuándo/qué sobre el valor curado.
     * El formato '@annotation' está verificado contra la instalación real
     * (2026-06-25): se escribe correctamente como value annotation. Si hay
     * justificación de la IA (TASK-023), se añade como dcterms:description (el
     * «porqué», distinto del «qué» de dcterms:provenance), acotada en longitud.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function annotation(string $contributor, string $when, string $term, string $reason = ''): array
    {
        $annotation = [];
        $map = [
            'dcterms:contributor' => $contributor,
            'dcterms:modified' => $when,
            'dcterms:provenance' => sprintf('OERManager re-catalogación de %s', $term),
        ];
        if ('' !== $reason) {
            $map['dcterms:description'] = mb_substr($reason, 0, self::MAX_REASON_CHARS);
        }
        foreach ($map as $annTerm => $literal) {
            $annPropertyId = $this->propertyId($annTerm);
            if (null === $annPropertyId) {
                continue;
            }
            $annotation[$annTerm] = [[
                'type' => 'literal',
                'property_id' => $annPropertyId,
                '@value' => $literal,
            ]];
        }
        return $annotation;
    }

    /**
     * @return array{ids:int[],titles:array<int,string>}
     */
    private function currentTargets(ItemRepresentation $item, string $term): array
    {
        $ids = [];
        $titles = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if ($resource) {
                $id = (int) $resource->id();
                $ids[] = $id;
                $titles[$id] = $this->qualifiedTitle($resource, $term);
            }
        }
        sort($ids);
        return ['ids' => $ids, 'titles' => $titles];
    }

    /**
     * Título del item-término con su ancestro entre paréntesis (D7). El currículo
     * repite el mismo título de asignatura en cada curso (p. ej. «Conocimiento del
     * Medio Natural, Social y cultural» existe en 3º, 4º y 5º de Primaria), así que
     * un diff que liste solo títulos le muestra al curador líneas idénticas que no
     * puede distinguir. Sin lecturas nuevas por id: la arista se recorre sobre la
     * representación que ya se tenía.
     */
    private function qualifiedTitle(AbstractResourceEntityRepresentation $target, string $dimension): string
    {
        $title = (string) $target->displayTitle();
        if (self::UNQUALIFIED_TERM === $dimension) {
            return $title;
        }
        foreach (self::QUALIFIER_TERMS as $term) {
            $value = $target->value($term);
            $ancestor = $value ? $value->valueResource() : null;
            if (null === $ancestor) {
                continue;
            }
            $ancestorTitle = trim((string) $ancestor->displayTitle());
            if ('' !== $ancestorTitle) {
                return $title . ' (' . $ancestorTitle . ')';
            }
        }
        return $title;
    }

    /**
     * @param array<int|string> $ids
     * @return int[]
     */
    private function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        sort($ids);
        return $ids;
    }

    /**
     * Destinos inválidos para una dimensión: los que no existen como item o no
     * son del tipo esperado (skill recatalogador: validar que el destino existe
     * y es del tipo esperado). El POST es manipulable, así que no basta con que
     * el id exista: debe ser un término de ESA dimensión.
     *
     * @param int[] $ids
     * @param array<int,string> $titles se rellena por referencia con el título de
     *   los destinos que sí existen, para no releerlos luego (D7)
     * @return int[]
     */
    private function invalidTargets(string $term, array $ids, array &$titles = []): array
    {
        $invalid = [];
        foreach ($ids as $id) {
            try {
                $item = $this->api->read('items', $id)->getContent();
            } catch (\Exception $e) {
                $invalid[] = $id;
                continue;
            }
            $titles[(int) $id] = $this->qualifiedTitle($item, $term);
            if (!$this->matchesDimension($term, $item)) {
                $invalid[] = $id;
            }
        }
        return $invalid;
    }

    /**
     * ¿El item-término pertenece a la dimensión $term? Curriculares: su
     * dcterms:type coincide con el valor configurado (ADR-0009). Ejes: pertenece
     * al DefinedTermSet de ejes configurado (ADR-0006). Si la dimensión no está
     * configurada, no se puede validar el tipo y no se bloquea (solo existencia).
     */
    private function matchesDimension(string $term, ItemRepresentation $item): bool
    {
        if ('dcterms:relation' === $term) {
            $axisId = (int) $this->settings->get(CurriculumSearch::AXIS_SETTING);
            if ($axisId <= 0) {
                return true;
            }
            foreach ($item->value(CurriculumSearch::IN_TERMSET_TERM, ['all' => true, 'default' => []]) as $value) {
                $resource = $value->valueResource();
                if ($resource && (int) $resource->id() === $axisId) {
                    return true;
                }
            }
            return false;
        }

        $setting = CurriculumSearch::TYPE_SETTINGS[$term] ?? null;
        if (null === $setting) {
            return true;
        }
        $expected = trim((string) $this->settings->get($setting));
        if ('' === $expected) {
            return true;
        }
        $typeValue = $item->value(CurriculumSearch::TYPE_TERM);
        return null !== $typeValue && trim((string) $typeValue) === $expected;
    }

    private function propertyId(string $term): ?int
    {
        if (array_key_exists($term, $this->propertyIds)) {
            return $this->propertyIds[$term];
        }
        $content = $this->api->search('properties', ['term' => $term])->getContent();
        return $this->propertyIds[$term] = $content ? $content[0]->id() : null;
    }
}
