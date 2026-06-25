<?php

namespace OERManager\Service\Llm;

/**
 * Error de la conexión LLM (transporte o proveedor). El mensaje NUNCA debe
 * incluir la clave API ni el contenido de los medios (CLAUDE.md §Seguridad).
 */
class LlmException extends \RuntimeException
{
}
