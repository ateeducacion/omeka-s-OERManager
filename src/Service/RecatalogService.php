<?php

namespace OERManager\Service;

use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Service\Governance\CurationHistory;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueAnnotationRepresentation;
use Omeka\Settings\Settings;

/**
 * Escritura del alineamiento curricular y los ejes temáticos como resource
 * values RDF (RF-004/RF-005, ADR-0004), con preview del diff, validación de
 * destino y auditoría reversible vía value annotations dcterms (ADR-0002).
 *
 * La reversibilidad es real desde TASK-007: además de anotar cada valor, cada
 * apply escribe un evento de curación sobre el propio item con el estado previo
 * (ADR-0015), que es lo único que una anotación no puede registrar —porque vive
 * en el valor que se borra—, y `undo()` lo usa para restaurarlo.
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

    private ApiManager $api;
    private Settings $settings;
    private CurationWriter $writer;
    private CurriculumTerms $terms;

    public function __construct(ApiManager $api, Settings $settings, CurationWriter $writer, CurriculumTerms $terms)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->writer = $writer;
        $this->terms = $terms;
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
            $invalid = $this->invalidTargets($term, $next, $current, $titles);
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
     * auditoría dcterms sobre cada valor y un evento de curación sobre el propio
     * item que registra el estado previo (TASK-007, ADR-0015). Lanza si algún
     * destino no existe.
     *
     * @param array<string,array<int|string>> $proposed
     * @param array<string,array<int,string>> $justifications term => {itemId => texto}
     *   justificación IA por saber/criterio (TASK-023); se anota como dcterms:description
     * @param string|null $undoOf `dcterms:modified` del evento que esta escritura revierte
     * @return array{updated:bool,properties:string[],unchanged?:bool,event?:array<string,string>}
     */
    public function apply(
        int $itemId,
        array $proposed,
        string $contributor,
        array $justifications = [],
        ?string $undoOf = null
    ): array {
        // Con precisión de segundo, dos escrituras seguidas (un doble clic en
        // «Deshacer») comparten sello y `lastEvent()` tendría que desempatar por
        // el orden de la colección, que Omeka no garantiza: restauraría el
        // estado equivocado. Los microsegundos hacen el sello único, y sigue
        // siendo el MISMO en el evento y en las anotaciones por valor, que es lo
        // que mantiene el par (contributor, modified) como clave del evento.
        $now = $this->writer->stamp();
        $item = $this->api->read('items', $itemId)->getContent();
        $data = [];
        $clear = [];
        $properties = [];
        $dimensions = [];
        foreach (self::ALIGNMENT_TERMS as $term) {
            if (!array_key_exists($term, $proposed)) {
                continue;
            }
            $propertyId = $this->writer->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            $ids = $this->normalizeIds($proposed[$term]);
            // El estado previo se lee ANTES de escribir: es lo único que una
            // value annotation no puede registrar, porque al borrarse el valor
            // se borra con él (ADR-0015). También decide qué retirados se
            // conservan: los que ya estaban enlazados.
            $current = $this->currentTargets($item, $term);
            $invalid = $this->invalidTargets($term, $ids, $current['ids']);
            if ($invalid) {
                throw new \RuntimeException(sprintf(
                    'Destinos inválidos para %s (inexistentes, de otra dimensión o retirados): %s',
                    $term,
                    implode(', ', $invalid)
                ));
            }
            $dimensions[$term] = [
                'before' => $current['ids'],
                'after' => $ids,
                'why' => $current['reasons'],
            ];
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
        // Un «Confirmar» que no cambia nada NO se escribe: reescribir los mismos
        // valores les pondría anotaciones con fecha y autor nuevos, falsificando
        // justo la auditoría que ADR-0002 viene a dar.
        $event = CurationEvent::build($dimensions, $undoOf);
        if (null === $event) {
            return ['updated' => false, 'unchanged' => true, 'properties' => []];
        }
        // El partial de Omeka reemplaza el set COMPLETO de values del item
        // (ValueHydrator recorre la colección plana y borra lo no reutilizado).
        // Para tocar SOLO las properties editadas: limpiar esas properties con
        // clear_property_values y anexar (collectionAction=append) los nuevos
        // valores; en modo append Omeka no reutiliza ni borra el resto, así que
        // título, descripción, licencia, proyecto, etc. quedan intactos.
        // El evento se ANEXA: dcterms:provenance nunca entra en
        // clear_property_values, así que el registro es append-only y ninguna
        // re-catalogación posterior borra la traza de las anteriores.
        $eventValue = $this->writer->eventValue($event, $contributor, $now);
        if ($eventValue) {
            $data['dcterms:provenance'] = [$eventValue];
        }
        $this->writer->commit($itemId, $clear, $data);
        return [
            'updated' => true,
            'properties' => $properties,
            'event' => ['when' => $now, 'summary' => CurationEvent::summary($event)],
        ];
    }

    /**
     * Último evento de curación del item, o null si no tiene ninguno legible.
     *
     * @return array{when:string,contributor:string,summary:string,payload:array<string,mixed>}|null
     */
    public function lastEvent(int $itemId): ?array
    {
        return $this->lastEventOf($this->api->read('items', $itemId)->getContent());
    }

    /**
     * Every readable curation event of the item, newest first, with the same
     * microsecond tie-break as lastEvent() (TASK-007). Raw payloads, not the
     * projected rows of history(): the batch undo (TASK-028 slice 5a) needs
     * the last two events and their `batch` keys.
     *
     * @return list<array{when:string,contributor:string,summary:string,payload:array<string,mixed>}>
     */
    public function events(int $itemId): array
    {
        return $this->eventsOf($this->api->read('items', $itemId)->getContent());
    }

    /**
     * Historial completo de curación del item, ya resuelto a títulos y listo
     * para pintar (rebanada 3a de TASK-028).
     *
     * Los títulos se cualifican con su curso ancestro porque el currículo repite
     * el mismo nombre en varios cursos —«Matemáticas» aparece en cuatro— y un
     * historial que liste solo títulos mostraría líneas idénticas que el curador
     * no puede distinguir. Es el mismo motivo por el que el diff del preview lo
     * hace desde TASK-028 rebanada 1.
     *
     * Coste: una lectura por id referenciado. Es por item y bajo demanda, no por
     * fila de tabla.
     *
     * @return list<array<string,mixed>> Ver CurationHistory::rows()
     */
    public function history(int $itemId): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $events = $this->eventsOf($item);
        if ([] === $events) {
            return [];
        }
        $titles = $this->titlesFor(CurationHistory::referencedIds($events), $this->dimensionsOf($events));
        return CurationHistory::rows($events, $titles);
    }

    /**
     * Dimensión (término) de la que viene cada id referenciado en los eventos.
     *
     * `titlesFor()` necesita el término real, no una constante, porque
     * `qualifiedTitle()` califica según la dimensión (CurModel::qualifierEdges):
     * los ejes (`dcterms:relation`), cursos y materias en curso no llevan
     * sufijo. Pasar siempre `lrmi:teaches` daría a todos el sufijo de saberes.
     *
     * Un id que apareciera en más de una dimensión conserva la primera que lo
     * referencia; en la práctica no colisiona porque cada dimensión resuelve
     * ids de su propio vocabulario.
     *
     * @param list<array{payload:array}> $events
     * @return array<int,string> id → término (p.ej. 'lrmi:teaches', 'dcterms:relation')
     */
    private function dimensionsOf(array $events): array
    {
        $dimensionOf = [];
        foreach ($events as $event) {
            foreach ($event['payload']['terms'] ?? [] as $term => $entry) {
                $ids = [...($entry['before'] ?? []), ...($entry['after'] ?? [])];
                foreach ($ids as $id) {
                    $dimensionOf[(int) $id] ??= (string) $term;
                }
            }
        }
        return $dimensionOf;
    }

    /**
     * Títulos cualificados de los ids dados. Un destino que ya no existe se
     * omite del mapa; `CurationHistory` lo rinde como `#<id>` en vez de romper.
     *
     * @param list<int> $ids
     * @param array<int,string> $dimensionOf id → término, ver dimensionsOf()
     * @return array<int,string>
     */
    private function titlesFor(array $ids, array $dimensionOf): array
    {
        $titles = [];
        foreach ($ids as $id) {
            try {
                $target = $this->api->read('items', $id)->getContent();
            } catch (\Exception $e) {
                continue;
            }
            $titles[$id] = $this->qualifiedTitle($target, $dimensionOf[$id] ?? 'lrmi:teaches');
        }
        return $titles;
    }

    /**
     * Deshace la última re-catalogación del item restaurando el estado previo
     * que guardó su evento, justificaciones de la IA incluidas.
     *
     * La reversión NO es un camino de escritura privilegiado: se reaplica por
     * `apply()`, así que hereda la validación de destino, las anotaciones por
     * valor y su propio evento. Deshacer un deshacer es, por tanto, rehacer.
     *
     * @param bool $force salta el chequeo de obsolescencia (lo confirma el curador)
     * @return array<string,mixed>
     */
    public function undo(int $itemId, string $contributor, bool $force = false): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $event = $this->lastEventOf($item);
        if (null === $event) {
            return ['updated' => false, 'error' => 'no-event'];
        }
        $payload = $event['payload'];

        // ¿Sigue el REA como lo dejó ese evento? Si no, alguien lo tocó por otra
        // vía (p. ej. el formulario nativo de Omeka) y deshacer tiraría su
        // trabajo sin avisar. El curador tiene que confirmarlo explícitamente.
        if (!$force) {
            $stale = [];
            foreach (CurationEvent::expectedTargets($payload) as $term => $expected) {
                sort($expected);
                if ($this->currentTargets($item, $term)['ids'] !== $expected) {
                    $stale[] = $term;
                }
            }
            if ($stale) {
                return ['updated' => false, 'error' => 'stale', 'terms' => $stale];
            }
        }

        // Un término del currículo puede haberse borrado desde entonces. Se
        // descarta informando, en vez de que apply() lance y el deshacer falle
        // entero por un destino de cinco.
        $targets = CurationEvent::restoreTargets($payload);
        $dropped = [];
        foreach ($targets as $term => $ids) {
            $invalid = $this->invalidTargets($term, $ids, $this->currentTargets($item, $term)['ids']);
            if ($invalid) {
                $dropped = array_merge($dropped, $invalid);
                $targets[$term] = array_values(array_diff($ids, $invalid));
            }
        }

        $result = $this->apply(
            $itemId,
            $targets,
            $contributor,
            CurationEvent::restoreReasons($payload),
            $event['when']
        );
        $result['dropped'] = $dropped;
        $result['undoneAt'] = $event['when'];
        return $result;
    }

    /**
     * Todos los eventos de curación legibles del item, del más reciente al más
     * antiguo.
     *
     * El desempate replica el de la versión anterior de `lastEventOf()`, que
     * usaba `>=`: a igualdad de instante gana el ÚLTIMO recorrido. No es un
     * detalle cosmético — TASK-007 tuvo aquí un defecto real (sellos a
     * precisión de segundo y orden de colección que Omeka no garantiza) que
     * restauraba el estado equivocado, y se cerró pasando los sellos a
     * microsegundos. Cambiar este orden reabre aquello.
     *
     * @return list<array{when:string,contributor:string,summary:string,payload:array<string,mixed>}>
     */
    private function eventsOf(ItemRepresentation $item): array
    {
        $found = [];
        $index = 0;
        foreach ($item->value('dcterms:provenance', ['all' => true, 'default' => []]) as $value) {
            $annotation = $value->valueAnnotation();
            if (null === $annotation) {
                continue;
            }
            // El marcador, no el resumen: el resumen es traducible y cambiaría.
            if (CurationEvent::MARKER !== $this->annotationText($annotation, 'dcterms:provenance')) {
                continue;
            }
            $payload = CurationEvent::decode($this->annotationText($annotation, 'dcterms:replaces'));
            if (null === $payload) {
                continue;
            }
            $found[] = [
                'index' => $index++,
                'event' => [
                    'when' => $this->annotationText($annotation, 'dcterms:modified'),
                    'contributor' => $this->annotationText($annotation, 'dcterms:contributor'),
                    'summary' => trim((string) $value->value()),
                    'payload' => $payload,
                ],
            ];
        }

        // ISO-8601 con offset fijo: el orden lexicográfico es el cronológico.
        usort($found, static function (array $a, array $b): int {
            return [$b['event']['when'], $b['index']] <=> [$a['event']['when'], $a['index']];
        });

        return array_column($found, 'event');
    }

    /**
     * Último evento de curación del item, o null si no tiene ninguno legible.
     *
     * @return array{when:string,contributor:string,summary:string,payload:array<string,mixed>}|null
     */
    private function lastEventOf(ItemRepresentation $item): ?array
    {
        return $this->eventsOf($item)[0] ?? null;
    }

    private function annotationText(ValueAnnotationRepresentation $annotation, string $term): string
    {
        $value = $annotation->value($term);
        return null === $value ? '' : trim((string) $value);
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
                '@annotation' => $this->writer->annotation(
                    $contributor,
                    $when,
                    sprintf('OERManager re-catalogación de %s', $term),
                    $reason
                ),
            ];
        }
        return $values;
    }

    /**
     * Estado actual de una dimensión. `reasons` recupera el «porqué» que la IA
     * dejó anotado en cada valor (TASK-023) para que el evento pueda guardarlo
     * y un deshacer lo restaure: regenerarlo costaría otra pasada de LLM.
     *
     * @return array{ids:int[],titles:array<int,string>,reasons:array<int,string>}
     */
    private function currentTargets(ItemRepresentation $item, string $term): array
    {
        $ids = [];
        $titles = [];
        $reasons = [];
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            $resource = $value->valueResource();
            if (!$resource) {
                continue;
            }
            $id = (int) $resource->id();
            $ids[] = $id;
            $titles[$id] = $this->qualifiedTitle($resource, $term);
            $annotation = $value->valueAnnotation();
            $reason = $annotation ? $this->annotationText($annotation, 'dcterms:description') : '';
            if ('' !== $reason) {
                $reasons[$id] = $reason;
            }
        }
        sort($ids);
        return ['ids' => $ids, 'titles' => $titles, 'reasons' => $reasons];
    }

    /**
     * Título del término con su ancestro entre paréntesis (D7). Con ADR-0022
     * solo lo necesitan saberes y criterios, cuyo `skos:prefLabel` empieza por
     * la notación: se califican con su materia en curso («… (Matemáticas · 1º
     * ESO)»). Cursos y materias en curso ya tienen rótulos únicos, y los ejes
     * son un vocabulario plano (CurModel::qualifierEdges() no da aristas). Sin
     * lecturas nuevas por id: la arista se recorre sobre la representación.
     */
    private function qualifiedTitle(AbstractResourceEntityRepresentation $target, string $dimension): string
    {
        $title = CurModel::label($target);
        foreach (CurModel::qualifierEdges($dimension) as $edge) {
            $ancestor = CurModel::target($target, $edge);
            $ancestorTitle = $ancestor ? CurModel::label($ancestor) : '';
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
     * Destinos inválidos para una dimensión: los que no existen como item, no
     * son del tipo esperado o están retirados y no estaban ya enlazados (skill
     * recatalogador: validar que el destino existe y es del tipo esperado). El
     * POST es manipulable, así que no basta con que el id exista: debe ser un
     * término de ESA dimensión. Un enlace a un término retirado se conserva
     * (TASK-064, decisión 5): la regla de integridad lo señalará.
     *
     * @param int[] $ids
     * @param int[] $linked destinos que el REA ya tiene en esta dimensión
     * @param array<int,string> $titles se rellena por referencia con el título de
     *   los destinos que sí existen, para no releerlos luego (D7)
     * @return int[]
     */
    private function invalidTargets(string $term, array $ids, array $linked = [], array &$titles = []): array
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
            $retiredAnew = CurModel::isRetired($item) && !in_array((int) $id, $linked, true);
            if ($retiredAnew || !$this->matchesDimension($term, $item)) {
                $invalid[] = $id;
            }
        }
        return $invalid;
    }

    /**
     * ¿El término pertenece a la dimensión $term? Currículo (ADR-0022): su
     * clase es la de la dimensión y está en el conjunto del currículo (si no
     * se puede resolver el conjunto, decide solo la clase). Ejes: pertenece al
     * DefinedTermSet de ejes configurado (ADR-0006); sin configurar, no se
     * bloquea.
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

        if (CurModel::classTerm($item) !== CurModel::classOf($term)) {
            return false;
        }
        $schemeId = $this->terms->schemeId();
        return null === $schemeId || array_key_exists($schemeId, $item->itemSets());
    }
}
