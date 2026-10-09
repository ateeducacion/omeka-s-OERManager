<?php

namespace OERManager\Service\Llm;

/**
 * Claves de configuración de la conexión LLM en los settings nativos de Omeka
 * (ADR-0008). La clave API se guarda en settings y NUNCA se devuelve en claro al
 * formulario ni se loguea (write-only). Sin valores por defecto: el admin los
 * fija en la página de configuración del módulo.
 */
final class LlmSettings
{
    public const ENABLED = 'oermanager_llm_enabled';
    public const PROVIDER = 'oermanager_llm_provider';
    public const BASE_URL = 'oermanager_llm_base_url';
    public const MODEL = 'oermanager_llm_model';
    public const API_KEY = 'oermanager_llm_api_key';
    /** Tope de tokens del contenido enviado al LLM (≈ chars/4). */
    public const CONTENT_TOKEN_CAP = 'oermanager_llm_content_token_cap';

    /**
     * Modelo barato vision-capable para extracción/destilado (ADR-0011); si está
     * vacío, se reutiliza MODEL. El clasificador sigue usando MODEL.
     */
    public const EXTRACTION_MODEL = 'oermanager_llm_extraction_model';
    /** Master toggle de visión (egress de medios binarios a un tercero). */
    public const VISION_ENABLED = 'oermanager_llm_vision_enabled';
    /** Tope de imágenes enviadas a visión por item. */
    public const VISION_MAX_IMAGES = 'oermanager_llm_vision_max_images';

    /**
     * Perfil de inferencia compartido entre proveedores (paridad): un único valor
     * de temperatura y max_tokens que TODOS los pasos envían idéntico por ambos
     * adaptadores; sin él cada proveedor aplica sus defaults y los resultados
     * divergen (misma causa del no-determinismo etapa/materia visto en TASK-018).
     * Temperatura vacía = NO enviar (los Opus 4.6+ la rechazan con 400).
     */
    public const TEMPERATURE = 'oermanager_llm_temperature';
    public const MAX_TOKENS = 'oermanager_llm_max_tokens';

    /**
     * Modelo de decisiones para la selección fina de saberes y criterios
     * (TASK-062): desactivado por defecto; Jev a través de OpenRouter, con la
     * misma clave y base URL. Sin formulario todavía: se adopta tras el piloto.
     */
    public const DECISION_ENABLED = 'oermanager_llm_decision_enabled';
    public const DECISION_MODEL = 'oermanager_llm_decision_model';
    public const DECISION_THRESHOLD = 'oermanager_llm_decision_threshold';
    public const DEFAULT_DECISION_MODEL = 'typesafe/jev-1.13';
    public const DEFAULT_DECISION_THRESHOLD = 0.6;

    /**
     * Máximo de saberes y criterios que Jev propone, de los de P(sí) ≥ umbral
     * (0 = todos). Barrido de 2026-10-09 sobre los 52 REA: 4 y 3 dan F1 de hojas
     * en ciclo 0.37 frente a 0.34 del umbral 0.8, con el mismo recall.
     */
    public const DECISION_MAX_TEACHES = 'oermanager_llm_decision_max_teaches';
    public const DECISION_MAX_ASSESSES = 'oermanager_llm_decision_max_assesses';
    public const DEFAULT_DECISION_MAX_TEACHES = 4;
    public const DEFAULT_DECISION_MAX_ASSESSES = 3;
    private const DECISION_MAX_LIMIT = 200;

    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI = 'openai';

    public const VISION_MAX_PDF_BYTES = 'oermanager_llm_vision_max_pdf_bytes';

    public const DEFAULT_CONTENT_TOKEN_CAP = 6000;
    public const DEFAULT_VISION_MAX_IMAGES = 3;
    public const DEFAULT_MAX_TOKENS = 1024;
    // Tope del PDF (binario) enviado a la visión, separado del tope de PARSEO
    // (ContentExtractor::max_pdf_bytes, 20 MB, guarda anti PDF-bomb). 32 MB = el
    // límite documentado de PDF de entrada de Anthropic (TASK-025).
    public const DEFAULT_VISION_MAX_PDF_BYTES = 33554432;

    /**
     * Temperatura del perfil: null = no enviar (default del proveedor). Acepta
     * numérico en [0, 2] (el subconjunto común es 0-1; Anthropic rechaza >1).
     */
    public static function parseTemperature(mixed $raw): ?float
    {
        if (null === $raw) {
            return null;
        }
        $value = trim((string) $raw);
        if ('' === $value || !is_numeric($value)) {
            return null;
        }
        $temperature = (float) $value;
        return ($temperature >= 0.0 && $temperature <= 2.0) ? $temperature : null;
    }

    /** Umbral de P(sí) para proponer un saber o criterio: probabilidad en (0, 1]; si no, el default. */
    public static function parseDecisionThreshold(mixed $raw): float
    {
        $value = trim((string) $raw);
        if (!is_numeric($value)) {
            return self::DEFAULT_DECISION_THRESHOLD;
        }
        $threshold = (float) $value;
        return ($threshold > 0.0 && $threshold <= 1.0) ? $threshold : self::DEFAULT_DECISION_THRESHOLD;
    }

    /**
     * Topes de propuesta por dimensión: entero en [0, 200] (0 = sin tope); si no, el default.
     *
     * @return array{'lrmi:teaches': int, 'lrmi:assesses': int}
     */
    public static function decisionCaps(mixed $teaches, mixed $assesses): array
    {
        $parse = static function (mixed $raw, int $default): int {
            $value = trim((string) $raw);
            if (1 !== preg_match('/^\d+$/', $value) || (int) $value > self::DECISION_MAX_LIMIT) {
                return $default;
            }
            return (int) $value;
        };
        return [
            'lrmi:teaches' => $parse($teaches, self::DEFAULT_DECISION_MAX_TEACHES),
            'lrmi:assesses' => $parse($assesses, self::DEFAULT_DECISION_MAX_ASSESSES),
        ];
    }

    /** max_tokens del perfil: entero positivo o el default. */
    public static function parseMaxTokens(mixed $raw): int
    {
        $value = trim((string) $raw);
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_TOKENS;
        }
        $maxTokens = (int) $value;
        return $maxTokens > 0 ? $maxTokens : self::DEFAULT_MAX_TOKENS;
    }

    /** Tope de PDF para la visión (bytes): entero positivo o el default. */
    public static function parseVisionMaxPdfBytes(mixed $raw): int
    {
        $value = trim((string) $raw);
        if (!is_numeric($value)) {
            return self::DEFAULT_VISION_MAX_PDF_BYTES;
        }
        $bytes = (int) $value;
        return $bytes > 0 ? $bytes : self::DEFAULT_VISION_MAX_PDF_BYTES;
    }
}
