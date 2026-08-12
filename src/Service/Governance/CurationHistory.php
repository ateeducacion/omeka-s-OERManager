<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

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
                $before = array_map('intval', $entry['before'] ?? []);
                $after = array_map('intval', $entry['after'] ?? []);
                $addedIds = array_values(array_diff($after, $before));
                $removedIds = array_values(array_diff($before, $after));

                if ([] === $addedIds && [] === $removedIds) {
                    continue;
                }

                $why = [];
                foreach ($entry['why'] ?? [] as $id => $reason) {
                    $why[(int) $id] = (string) $reason;
                }

                $removed = [];
                foreach ($removedIds as $id) {
                    $removed[] = [
                        'title' => self::title($id, $titles),
                        'reason' => $why[$id] ?? '',
                    ];
                }

                $changes[] = [
                    'term' => (string) $term,
                    'added' => array_map(
                        static fn (int $id): string => self::title($id, $titles),
                        $addedIds
                    ),
                    'removed' => $removed,
                    'emptied' => [] === $after && [] !== $before,
                ];
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

    /** @param array<int,string> $titles */
    private static function title(int $id, array $titles): string
    {
        $title = trim((string) ($titles[$id] ?? ''));
        return '' === $title ? self::UNRESOLVED_PREFIX . $id : $title;
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
