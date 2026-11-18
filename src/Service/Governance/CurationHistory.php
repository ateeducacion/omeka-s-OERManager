<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use OERManager\Service\CurationEvent;

/**
 * Modelo de presentación del historial de curación (ADR-0015).
 *
 * El historial de la rebanada 3a sale de los EVENTOS `dcterms:provenance`, no
 * de las value annotations: una anotación vive en el valor, así que al vaciar
 * una dimensión desaparece con ella y el vaciado —el cambio más destructivo que
 * el curador puede hacer— sería justo el que no se ve. El evento sí lo registra.
 *
 * Pura a propósito: la lectura de los valores y la resolución de títulos las
 * hace `RecatalogService`, que sí depende del core.
 *
 * **Semántica del porqué, fácil de invertir:** en el payload, `why` guarda la
 * justificación de los valores PREVIOS, porque el deshacer los restaura. El
 * historial la muestra por tanto junto a los valores RETIRADOS. La del valor
 * actual vive en la anotación de ese valor, que esta rebanada no lee.
 */
final class CurationHistory
{
    /** Marca de un id cuyo destino ya no se puede resolver. */
    public const UNRESOLVED_PREFIX = '#';

    /**
     * @param list<array{when:string,contributor:string,summary:string,payload:array}> $events
     *        Ya ordenados de más reciente a más antiguo.
     * @param array<int,string> $titles id → título resuelto
     * @return list<array{when:string,whenLabel:string,contributor:string,summary:string,isUndo:bool,changes:list<array{
     *     term:string, added:list<string>, removed:list<array{title:string,reason:string}>, emptied:bool
     * }>}>
     */
    public static function rows(array $events, array $titles): array
    {
        $rows = [];
        foreach ($events as $event) {
            $payload = $event['payload'];
            $changes = [];

            foreach ($payload['terms'] ?? [] as $term => $entry) {
                $change = CurationEvent::isTyped($payload)
                    ? self::typedChange((string) $term, $entry)
                    : self::linkChange((string) $term, $entry, $titles);
                if (null !== $change) {
                    $changes[] = $change;
                }
            }

            $rows[] = [
                'when' => (string) $event['when'],
                'whenLabel' => self::formatWhen((string) $event['when']),
                'contributor' => (string) $event['contributor'],
                'summary' => (string) $event['summary'],
                'isUndo' => 'undo' === ($payload['op'] ?? ''),
                'changes' => $changes,
            ];
        }

        return $rows;
    }

    /**
     * Ids de item que el historial necesita resolver a título, sin repetir.
     *
     * @param list<array{payload:array}> $events
     * @return list<int>
     */
    public static function referencedIds(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            if (CurationEvent::isTyped($event['payload'])) {
                continue;
            }
            foreach ($event['payload']['terms'] ?? [] as $entry) {
                $allIds = [
                    ...($entry['before'] ?? []),
                    ...($entry['after'] ?? []),
                ];
                foreach ($allIds as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<int,string> $titles
     * @return array{term:string,added:list<string>,removed:list<array{title:string,reason:string}>,emptied:bool}|null
     */
    private static function linkChange(string $term, array $entry, array $titles): ?array
    {
        $before = array_map('intval', $entry['before'] ?? []);
        $after = array_map('intval', $entry['after'] ?? []);
        $addedIds = array_values(array_diff($after, $before));
        $removedIds = array_values(array_diff($before, $after));
        if ([] === $addedIds && [] === $removedIds) {
            return null;
        }

        $why = [];
        foreach ($entry['why'] ?? [] as $id => $reason) {
            $why[(int) $id] = (string) $reason;
        }
        $stored = [];
        foreach ($entry['labels'] ?? [] as $id => $label) {
            $stored[(int) $id] = (string) $label;
        }

        $removed = [];
        foreach ($removedIds as $id) {
            $removed[] = ['title' => self::title($id, $titles, $stored), 'reason' => $why[$id] ?? ''];
        }

        return [
            'term' => $term,
            'added' => array_map(static fn (int $id): string => self::title($id, $titles, $stored), $addedIds),
            'removed' => $removed,
            'emptied' => [] === $after && [] !== $before,
        ];
    }

    /**
     * Governance values (ADR-0020) are not links: they are compared by their
     * canonical form and shown as their label, URI or literal.
     *
     * @param array<string,mixed> $entry
     * @return array{term:string,added:list<string>,removed:list<array{title:string,reason:string}>,emptied:bool}|null
     */
    private static function typedChange(string $term, array $entry): ?array
    {
        $before = self::keyed($entry['before'] ?? []);
        $after = self::keyed($entry['after'] ?? []);
        $added = array_values(array_diff_key($after, $before));
        $removed = array_values(array_diff_key($before, $after));
        if ([] === $added && [] === $removed) {
            return null;
        }
        return [
            'term' => $term,
            'added' => $added,
            'removed' => array_map(static fn (string $text): array => ['title' => $text, 'reason' => ''], $removed),
            'emptied' => [] === $after && [] !== $before,
        ];
    }

    /**
     * Canonical key → display text of each typed value.
     *
     * @param list<mixed> $values
     * @return array<string,string>
     */
    private static function keyed(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            ksort($value);
            $text = 'uri' === ($value['type'] ?? '')
                ? ValueText::of($value['label'] ?? null, $value['uri'] ?? null)
                : trim((string) ($value['value'] ?? ''));
            $out[(string) json_encode($value)] = $text;
        }
        return $out;
    }

    /**
     * @param array<int,string> $titles live titles
     * @param array<int,string> $stored labels the event stored when it was written
     */
    private static function title(int $id, array $titles, array $stored = []): string
    {
        foreach ([$titles[$id] ?? '', $stored[$id] ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);
            if ('' !== $candidate) {
                return $candidate;
            }
        }
        return self::UNRESOLVED_PREFIX . $id;
    }

    /**
     * `when` legible para el curador. El crudo (`Y-m-d\TH:i:s.uP`, ver
     * `RecatalogService::apply()`) lleva microsegundos por una razón de
     * CORRECCIÓN (desempate del deshacer, no de presentación); `when` se
     * conserva tal cual en la fila y esta es solo su etiqueta para pintar.
     *
     * Un `when` que no se pudiera parsear (dato de otra fuente, versión futura
     * del formato) cae al propio crudo en vez de reventar el historial entero.
     */
    private static function formatWhen(string $when): string
    {
        try {
            $date = new \DateTimeImmutable($when);
        } catch (\Exception $e) {
            return $when;
        }
        return $date->format('d/m/Y H:i');
    }
}
