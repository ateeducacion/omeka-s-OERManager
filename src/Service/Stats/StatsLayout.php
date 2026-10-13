<?php

declare(strict_types=1);

namespace OERManager\Service\Stats;

/**
 * Disposición de «Conteo por dimensión» de cada curador (revisión de TASK-047,
 * 2026-10-11): orden de las tarjetas y etapas plegadas, guardada como ajuste
 * nativo de usuario de Omeka. Lo que llega del navegador es dato no confiable:
 * solo sobreviven las tarjetas conocidas y las claves de etapa (un id numérico
 * o «none» para el grupo sin etapa); cualquier otra cosa cae al valor por
 * defecto, que es la página tal como era.
 */
final class StatsLayout
{
    public const SETTING = 'oermanager_stats_layout';

    /** Tarjetas en el orden por defecto (el de la página antes de la revisión). */
    public const CARDS = ['etapa', 'materia', 'eje', 'proyecto', 'licencia', 'completitud'];

    /** Tarjetas con etapas plegables. */
    public const COLLAPSIBLE = ['etapa', 'materia'];

    /** Tope del JSON aceptado: la disposición real ocupa unos cientos de bytes. */
    private const MAX_BYTES = 4096;

    /** Tope de etapas plegadas por tarjeta (el currículo tiene media docena). */
    private const MAX_STAGES = 50;

    /** @return array{order: list<string>, collapsed: array<string, list<string>>} */
    public static function defaults(): array
    {
        return ['order' => self::CARDS, 'collapsed' => array_fill_keys(self::COLLAPSIBLE, [])];
    }

    /**
     * @param mixed $raw JSON (lo que manda el navegador) o array (lo guardado)
     * @return array{order: list<string>, collapsed: array<string, list<string>>}
     */
    public static function sanitize(mixed $raw): array
    {
        if (is_string($raw)) {
            if (strlen($raw) > self::MAX_BYTES) {
                return self::defaults();
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return self::defaults();
        }

        $order = [];
        foreach ((array) ($raw['order'] ?? []) as $card) {
            if (is_string($card) && in_array($card, self::CARDS, true) && !in_array($card, $order, true)) {
                $order[] = $card;
            }
        }
        foreach (self::CARDS as $card) {
            if (!in_array($card, $order, true)) {
                $order[] = $card;
            }
        }

        $collapsed = [];
        foreach (self::COLLAPSIBLE as $card) {
            $keys = [];
            foreach ((array) ($raw['collapsed'][$card] ?? []) as $key) {
                $key = is_int($key) ? (string) $key : $key;
                $valid = is_string($key) && 1 === preg_match('/^(?:[1-9]\d{0,8}|none)$/', $key);
                if ($valid && !in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
            if (count($keys) > self::MAX_STAGES) {
                return self::defaults();
            }
            $collapsed[$card] = $keys;
        }

        return ['order' => $order, 'collapsed' => $collapsed];
    }
}
