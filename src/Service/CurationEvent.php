<?php

namespace OERManager\Service;

/**
 * Formato del evento de curación (TASK-007, ADR-0015): el registro que hace real
 * la reversibilidad que ADR-0002 dejó a medias.
 *
 * Una value annotation no puede registrar una ELIMINACIÓN, porque vive en el
 * valor que se borra: al vaciar una dimensión desaparece con ella. Por eso cada
 * apply que cambia algo escribe además un valor en el propio item con el estado
 * previo de las dimensiones tocadas. Esta clase es la dueña única de ese formato
 * y es pura a propósito: RecatalogService depende del core de Omeka y no se
 * puede instanciar en el arnés del host, así que aislar aquí el payload —de cuya
 * exactitud depende que un deshacer restaure el REA— es lo que permite probarlo.
 */
class CurationEvent
{
    /**
     * Marcador que identifica el valor como evento del módulo. ASCII estable y
     * NO traducible: reconocerlo por el resumen legible se rompería en cuanto se
     * compilase un .mo.
     */
    public const MARKER = 'OERManager/curation-event/1';

    /** Versión del payload; `decode` rechaza lo que no sepa leer. */
    public const VERSION = 1;

    public const OP_RECATALOG = 'recatalog';
    public const OP_UNDO = 'undo';

    /**
     * Payload del evento, o null si ninguna dimensión cambió (un «Confirmar»
     * sin cambios no es un evento: reescribirlo re-sellaría todas las
     * anotaciones con una fecha nueva y falsificaría la auditoría).
     *
     * @param array<string,array{before:int[],after:int[],why?:array<int,string>}> $dimensions
     * @param string|null $undoOf `dcterms:modified` del evento que esto revierte
     * @return array<string,mixed>|null
     */
    public static function build(array $dimensions, ?string $undoOf = null): ?array
    {
        $terms = [];
        foreach ($dimensions as $term => $state) {
            $before = array_values($state['before']);
            $after = array_values($state['after']);
            if (!self::changed($before, $after)) {
                continue;
            }
            $entry = ['before' => $before, 'after' => $after];
            // El porqué de TODOS los valores previos, no solo el de los que se
            // van: el deshacer reescribe la property entera desde `before`, así
            // que cada valor restaurado necesita el suyo de vuelta.
            $why = [];
            foreach ($state['why'] ?? [] as $id => $reason) {
                if (in_array((int) $id, $before, true) && '' !== trim((string) $reason)) {
                    $why[(int) $id] = (string) $reason;
                }
            }
            if ($why) {
                $entry['why'] = $why;
            }
            $terms[$term] = $entry;
        }
        if (!$terms) {
            return null;
        }
        return [
            'v' => self::VERSION,
            'op' => null === $undoOf ? self::OP_RECATALOG : self::OP_UNDO,
            'undoOf' => $undoOf,
            'terms' => $terms,
        ];
    }

    /**
     * Resumen legible que va en el propio valor, para que quien abra el item
     * entienda qué pasó sin parsear el payload.
     *
     * @param array<string,mixed> $payload
     */
    public static function summary(array $payload): string
    {
        $typed = self::isTyped($payload);
        $parts = [];
        foreach ($payload['terms'] as $term => $entry) {
            if ($typed) {
                // Typed values are arrays, not ids: array_diff would coerce
                // them to strings. Count by entry, comparing a canonical
                // form (sorted keys) instead of an id.
                $added = count(array_diff(self::valueKeys($entry['after']), self::valueKeys($entry['before'])));
                $removed = count(array_diff(self::valueKeys($entry['before']), self::valueKeys($entry['after'])));
            } else {
                $added = count(array_diff($entry['after'], $entry['before']));
                $removed = count(array_diff($entry['before'], $entry['after']));
            }
            $counts = [];
            if ($added) {
                $counts[] = '+' . $added;
            }
            if ($removed) {
                $counts[] = '−' . $removed;
            }
            $line = $term . ' ' . implode(' ', $counts);
            if (!$entry['after']) {
                $line .= ' (vaciada)';
            }
            $parts[] = $line;
        }
        $head = self::OP_UNDO === $payload['op']
            ? 'Reversión' // @translate
            : (self::OP_GOVERNANCE === $payload['op'] ? 'Gobernanza' : 'Re-catalogación'); // @translate
        return $head . ' · ' . implode(' · ', $parts);
    }

    /**
     * Canonical form of a list of typed values, so they can be compared like
     * ids with array_diff.
     *
     * @param list<array<string,string>> $values
     * @return list<string>
     */
    private static function valueKeys(array $values): array
    {
        return array_map(
            static function (array $value): string {
                ksort($value);
                return (string) json_encode($value);
            },
            $values
        );
    }

    /** @param array<string,mixed> $payload */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Payload válido, o null ante basura o una versión que no sabemos leer. Se
     * rechaza en vez de interpretarse a medias: un deshacer sobre un payload mal
     * entendido escribe RDF incorrecto en el catálogo.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !in_array($data['v'] ?? null, [self::VERSION, self::VERSION_TYPED], true)) {
            return null;
        }
        if (!isset($data['terms']) || !is_array($data['terms']) || !$data['terms']) {
            return null;
        }
        foreach ($data['terms'] as $entry) {
            if (
                !is_array($entry) || !isset($entry['before'], $entry['after'])
                || !is_array($entry['before']) || !is_array($entry['after'])
            ) {
                return null;
            }
        }
        return $data;
    }

    /**
     * Estado al que devuelve el deshacer: term => ids previos.
     *
     * @param array<string,mixed> $payload
     * @return array<string,int[]>
     */
    public static function restoreTargets(array $payload): array
    {
        $targets = [];
        foreach ($payload['terms'] as $term => $entry) {
            $targets[$term] = array_map('intval', $entry['before']);
        }
        return $targets;
    }

    /**
     * Justificaciones de la IA a restaurar: term => {itemId => porqué}.
     *
     * @param array<string,mixed> $payload
     * @return array<string,array<int,string>>
     */
    public static function restoreReasons(array $payload): array
    {
        $reasons = [];
        foreach ($payload['terms'] as $term => $entry) {
            if (!empty($entry['why'])) {
                $reasons[$term] = $entry['why'];
            }
        }
        return $reasons;
    }

    /**
     * Estado que el evento dejó escrito: term => ids resultantes. Comparar esto
     * con el estado actual es lo que detecta que alguien tocó el REA por otra
     * vía después, y que por tanto deshacer destruiría trabajo ajeno.
     *
     * @param array<string,mixed> $payload
     * @return array<string,int[]>
     */
    public static function expectedTargets(array $payload): array
    {
        $targets = [];
        foreach ($payload['terms'] as $term => $entry) {
            $targets[$term] = array_map('intval', $entry['after']);
        }
        return $targets;
    }

    /**
     * @param int[] $before
     * @param int[] $after
     */
    private static function changed(array $before, array $after): bool
    {
        sort($before);
        sort($after);
        return $before !== $after;
    }

    /** Payload version whose before/after carry typed values (ADR-0020). */
    public const VERSION_TYPED = 2;

    public const OP_GOVERNANCE = 'governance';

    /**
     * Event for values that are not links: literals and URIs (ADR-0020).
     * Comparison is by ordered list — reordering authors is a change — unlike
     * v1, where curriculum dimensions compare as sets.
     *
     * @param array<string,array{before:list<array<string,string>>,after:list<array<string,string>>}> $terms
     * @return array<string,mixed>|null null when nothing changed
     */
    public static function buildTyped(array $terms, ?string $undoOf = null): ?array
    {
        $changed = [];
        foreach ($terms as $term => $state) {
            $before = array_values($state['before']);
            $after = array_values($state['after']);
            if ($before === $after) {
                continue;
            }
            $changed[$term] = ['before' => $before, 'after' => $after];
        }
        if (!$changed) {
            return null;
        }
        return [
            'v' => self::VERSION_TYPED,
            'op' => null === $undoOf ? self::OP_GOVERNANCE : self::OP_UNDO,
            'undoOf' => $undoOf,
            'terms' => $changed,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function isTyped(array $payload): bool
    {
        return self::VERSION_TYPED === ($payload['v'] ?? null);
    }

    /**
     * Which service can replay this event. The terms say it, not the version:
     * an event never mixes curriculum terms with governance terms (ADR-0020 §4).
     *
     * @param array<string,mixed> $payload
     */
    public static function scopeOf(array $payload): string
    {
        foreach (array_keys($payload['terms'] ?? []) as $term) {
            if (Governance\GovernanceFields::isGovernanceTerm((string) $term)) {
                return 'governance';
            }
        }
        return 'curriculum';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,list<array<string,string>>>
     */
    public static function restoreValues(array $payload): array
    {
        $values = [];
        foreach ($payload['terms'] as $term => $entry) {
            $values[$term] = array_values($entry['before']);
        }
        return $values;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,list<array<string,string>>>
     */
    public static function expectedValues(array $payload): array
    {
        $values = [];
        foreach ($payload['terms'] as $term => $entry) {
            $values[$term] = array_values($entry['after']);
        }
        return $values;
    }
}
