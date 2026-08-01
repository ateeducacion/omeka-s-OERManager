<?php

namespace OERManager\Service;

use OERManager\Service\Llm\LlmSettings;

/**
 * Mapeo entre los settings del módulo y su formulario de configuración
 * (TASK-029).
 *
 * Vivía en `Module::getConfigForm()`/`handleConfigForm()`, donde no había forma
 * de probarlo: ambos métodos exigen el contenedor de servicios de Omeka. Al
 * mover la configuración al menú lateral se extrae aquí, puro y sin una sola
 * referencia al core —el lector entra como `callable`, igual que en
 * `ResourceTypeVocab`—, para que el arnés del host pueda ejercitarlo.
 *
 * Invariante que justifica la clase: la **clave API es write-only**. `read()`
 * no la devuelve nunca y `write()` solo la incluye si llega un valor no vacío,
 * de modo que abrir la configuración y guardar sin tocar el campo no deja el
 * módulo sin credenciales.
 */
class ConfigPayload
{
    /** Vocabularios y plantilla de la gobernanza del catálogo (ADR-0013). */
    private const GOVERNANCE_ID_SETTINGS = [
        GovernanceSettings::LICENCE_VOCAB_ID,
        GovernanceSettings::RESOURCE_TYPE_VOCAB_ID,
        GovernanceSettings::REA_TEMPLATE_ID,
    ];

    /**
     * Datos con los que se rellena el formulario.
     *
     * @param callable $get fn(string $key, mixed $default = null): mixed
     * @return array<string,mixed>
     */
    public static function read(callable $get): array
    {
        $data = [
            CurriculumSearch::AXIS_SETTING => $get(CurriculumSearch::AXIS_SETTING),
            CurriculumSearch::FRAMEWORK_SETTING => $get(CurriculumSearch::FRAMEWORK_SETTING),
        ];
        foreach (CurriculumSearch::TYPE_SETTINGS as $setting) {
            $data[$setting] = $get($setting);
        }
        foreach (self::GOVERNANCE_ID_SETTINGS as $setting) {
            $data[$setting] = $get($setting);
        }
        $data[GovernanceSettings::DEFAULT_RIGHTS_HOLDER] = $get(GovernanceSettings::DEFAULT_RIGHTS_HOLDER);

        // Conexión LLM (TASK-010, ADR-0008). La clave API NO se devuelve en
        // claro: el campo se pinta vacío y solo se actualiza si el admin escribe.
        $data[LlmSettings::ENABLED] = (bool) $get(LlmSettings::ENABLED);
        $data[LlmSettings::PROVIDER] = $get(LlmSettings::PROVIDER, LlmSettings::PROVIDER_ANTHROPIC);
        $data[LlmSettings::BASE_URL] = $get(LlmSettings::BASE_URL);
        $data[LlmSettings::MODEL] = $get(LlmSettings::MODEL);
        $data[LlmSettings::CONTENT_TOKEN_CAP] = $get(
            LlmSettings::CONTENT_TOKEN_CAP,
            LlmSettings::DEFAULT_CONTENT_TOKEN_CAP
        );
        // Perfil de inferencia compartido (paridad entre proveedores).
        $data[LlmSettings::TEMPERATURE] = $get(LlmSettings::TEMPERATURE);
        $data[LlmSettings::MAX_TOKENS] = $get(LlmSettings::MAX_TOKENS, LlmSettings::DEFAULT_MAX_TOKENS);
        // Capa de contexto del LLM (ADR-0011): extracción/visión.
        $data[LlmSettings::EXTRACTION_MODEL] = $get(LlmSettings::EXTRACTION_MODEL);
        $data[LlmSettings::VISION_ENABLED] = (bool) $get(LlmSettings::VISION_ENABLED);
        $data[LlmSettings::VISION_MAX_IMAGES] = $get(
            LlmSettings::VISION_MAX_IMAGES,
            LlmSettings::DEFAULT_VISION_MAX_IMAGES
        );
        $data[LlmSettings::VISION_MAX_PDF_BYTES] = $get(
            LlmSettings::VISION_MAX_PDF_BYTES,
            LlmSettings::DEFAULT_VISION_MAX_PDF_BYTES
        );
        return $data;
    }

    /**
     * Settings a persistir a partir del POST. La clave API solo aparece en el
     * resultado si el admin introdujo una: lo que no está en el array no se
     * escribe, y así un campo en blanco no borra la credencial guardada.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function write(array $post): array
    {
        $axisId = (int) ($post[CurriculumSearch::AXIS_SETTING] ?? 0);
        $settings = [
            CurriculumSearch::AXIS_SETTING => $axisId > 0 ? $axisId : null,
            CurriculumSearch::FRAMEWORK_SETTING => trim((string) ($post[CurriculumSearch::FRAMEWORK_SETTING] ?? '')),
        ];
        foreach (CurriculumSearch::TYPE_SETTINGS as $setting) {
            $settings[$setting] = trim((string) ($post[$setting] ?? ''));
        }

        // Gobernanza del catálogo (ADR-0013). Los artefactos se identifican por
        // id; un valor vacío o no numérico se guarda como null = «sin
        // configurar», que hace degradar el campo a texto libre en vez de romper.
        foreach (self::GOVERNANCE_ID_SETTINGS as $setting) {
            $settings[$setting] = GovernanceSettings::parseId($post[$setting] ?? null);
        }
        $settings[GovernanceSettings::DEFAULT_RIGHTS_HOLDER] = GovernanceSettings::parseRightsHolder(
            $post[GovernanceSettings::DEFAULT_RIGHTS_HOLDER] ?? null
        );

        // Conexión LLM (TASK-010, ADR-0008).
        $settings[LlmSettings::ENABLED] = !empty($post[LlmSettings::ENABLED]);
        $provider = (string) ($post[LlmSettings::PROVIDER] ?? LlmSettings::PROVIDER_ANTHROPIC);
        $allowed = [LlmSettings::PROVIDER_ANTHROPIC, LlmSettings::PROVIDER_OPENAI];
        $settings[LlmSettings::PROVIDER] = in_array($provider, $allowed, true)
            ? $provider
            : LlmSettings::PROVIDER_ANTHROPIC;
        $settings[LlmSettings::BASE_URL] = trim((string) ($post[LlmSettings::BASE_URL] ?? ''));
        $settings[LlmSettings::MODEL] = trim((string) ($post[LlmSettings::MODEL] ?? ''));
        $cap = (int) ($post[LlmSettings::CONTENT_TOKEN_CAP] ?? 0);
        $settings[LlmSettings::CONTENT_TOKEN_CAP] = $cap > 0 ? $cap : LlmSettings::DEFAULT_CONTENT_TOKEN_CAP;

        // Perfil de inferencia compartido: temperatura vacía/no válida = no enviar.
        $temperature = LlmSettings::parseTemperature($post[LlmSettings::TEMPERATURE] ?? null);
        $settings[LlmSettings::TEMPERATURE] = null === $temperature ? '' : (string) $temperature;
        $settings[LlmSettings::MAX_TOKENS] = LlmSettings::parseMaxTokens($post[LlmSettings::MAX_TOKENS] ?? null);

        // Capa de contexto del LLM (ADR-0011): modelo de extracción + visión. La
        // visión arranca apagada (egress de binarios a un tercero); el modelo de
        // extracción vacío cae al del clasificador (lo resuelve la factoría).
        $settings[LlmSettings::EXTRACTION_MODEL] = trim((string) ($post[LlmSettings::EXTRACTION_MODEL] ?? ''));
        $settings[LlmSettings::VISION_ENABLED] = !empty($post[LlmSettings::VISION_ENABLED]);
        $maxImages = (int) ($post[LlmSettings::VISION_MAX_IMAGES] ?? 0);
        $settings[LlmSettings::VISION_MAX_IMAGES] = $maxImages > 0
            ? $maxImages
            : LlmSettings::DEFAULT_VISION_MAX_IMAGES;
        // Tope de envío de PDF a visión, separado del de parseo (TASK-025).
        $settings[LlmSettings::VISION_MAX_PDF_BYTES] = LlmSettings::parseVisionMaxPdfBytes(
            $post[LlmSettings::VISION_MAX_PDF_BYTES] ?? null
        );

        // Clave API write-only: solo se sobrescribe si llega un valor no vacío.
        $apiKey = (string) ($post[LlmSettings::API_KEY] ?? '');
        if ('' !== trim($apiKey)) {
            $settings[LlmSettings::API_KEY] = $apiKey;
        }
        return $settings;
    }
}
