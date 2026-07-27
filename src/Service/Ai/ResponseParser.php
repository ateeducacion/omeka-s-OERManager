<?php

namespace OERManager\Service\Ai;

/**
 * Parsea la respuesta del LLM a la lista de ÍNDICES de candidatos seleccionados
 * (1-based sobre la lista cerrada que se le mostró). La selección por índice es
 * barata en tokens y robusta al truncado, y evita la ambigüedad de títulos
 * repetidos (revisión adversaria, findings #1 y #4).
 *
 * Tolerante: extrae el objeto JSON aunque venga envuelto en prosa o en fences de
 * código, y solo lee la clave 'selected' (ignora cualquier otra clave que el
 * modelo pueda añadir, incluidas instrucciones inyectadas).
 */
final class ResponseParser
{
    /**
     * @return int[] índices 1-based seleccionados, sin <= 0 ni duplicados
     */
    public function parseIndices(string $text): array
    {
        $data = $this->decodeObject($text);
        if (!is_array($data) || !isset($data['selected']) || !is_array($data['selected'])) {
            return [];
        }
        $indices = [];
        foreach ($data['selected'] as $item) {
            if (is_int($item)) {
                $index = $item;
            } elseif (is_string($item) && 1 === preg_match('/^-?\d+$/', trim($item))) {
                $index = (int) trim($item);
            } else {
                continue;
            }
            if ($index > 0 && !in_array($index, $indices, true)) {
                $indices[] = $index;
            }
        }
        return $indices;
    }

    /**
     * Como parseIndices pero conservando la justificación por índice (pasos finos,
     * TASK-023). Degradante: acepta la forma {"i":n,"why":"…"}, tolera "why"
     * ausente y enteros pelados (el modelo puede ignorar la instrucción); nunca se
     * pierde una selección por el formato. Descarta índices <=0, duplicados
     * (el primero gana) y valores no numéricos.
     *
     * @return array<int,string> índice 1-based => justificación ('' si falta)
     */
    public function parseSelections(string $text): array
    {
        $data = $this->decodeObject($text);
        if (!is_array($data) || !isset($data['selected']) || !is_array($data['selected'])) {
            return [];
        }
        $out = [];
        foreach ($data['selected'] as $item) {
            $index = null;
            $why = '';
            if (is_array($item)) {
                $index = $this->toIndex($item['i'] ?? null);
                $why = is_string($item['why'] ?? null) ? trim($item['why']) : '';
            } else {
                $index = $this->toIndex($item);
            }
            if (null !== $index && $index > 0 && !array_key_exists($index, $out)) {
                $out[$index] = $why;
            }
        }
        return $out;
    }

    /**
     * Normaliza un valor a índice entero (int o string numérica), o null.
     *
     * @param mixed $value
     */
    private function toIndex(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }

    /**
     * Decodifica el primer objeto JSON del texto. Intenta el texto completo y,
     * si no es un objeto, extrae el tramo entre la primera '{' y la última '}'
     * (cubre prosa alrededor y fences ```json ... ```).
     *
     * @return array<string,mixed>|null
     */
    private function decodeObject(string $text): ?array
    {
        $text = trim($text);
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if (false !== $start && false !== $end && $end > $start) {
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }
}
