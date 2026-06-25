<?php

namespace OERManager\Service\Ai;

/**
 * Parsea la respuesta del LLM a la lista de etiquetas seleccionadas. Tolerante:
 * extrae el objeto JSON aunque venga envuelto en prosa o en fences de código, y
 * solo lee la clave 'selected' (ignora cualquier otra clave que el modelo pueda
 * añadir, incluidas instrucciones inyectadas). Devuelve etiquetas saneadas.
 */
final class ResponseParser
{
    /**
     * @return string[] etiquetas seleccionadas, sin vacíos ni duplicados
     */
    public function parseSelection(string $text): array
    {
        $data = $this->decodeObject($text);
        if (!is_array($data) || !isset($data['selected']) || !is_array($data['selected'])) {
            return [];
        }
        $selected = [];
        foreach ($data['selected'] as $item) {
            if (!is_string($item)) {
                continue;
            }
            $label = trim($item);
            if ('' === $label || in_array($label, $selected, true)) {
                continue;
            }
            $selected[] = $label;
        }
        return $selected;
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
