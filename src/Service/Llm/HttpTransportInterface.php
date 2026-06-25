<?php

namespace OERManager\Service\Llm;

/**
 * Transporte HTTP del cliente LLM (ADR-0008). Aísla Laminas\Http\Client (que
 * vive en el core de Omeka, no en el vendor del módulo) para que los adaptadores
 * LLM sean construibles y testeables en el host con un transporte falso.
 *
 * La implementación de producción (LaminasHttpTransport) solo habla con el
 * endpoint LLM configurado: no sigue redirecciones a hosts arbitrarios ni
 * descarga recursos referenciados en el contenido (sin SSRF, spec §6).
 */
interface HttpTransportInterface
{
    /**
     * @param array<string,string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResult;
}
