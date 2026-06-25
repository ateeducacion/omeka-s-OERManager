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

    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI = 'openai';

    public const DEFAULT_CONTENT_TOKEN_CAP = 6000;
}
