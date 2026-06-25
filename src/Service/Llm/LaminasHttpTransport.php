<?php

namespace OERManager\Service\Llm;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;

/**
 * Transporte HTTP de producción para el cliente LLM: envuelve Laminas\Http\Client
 * (incluido en el core de Omeka, sin dependencia composer nueva, ADR-0008).
 *
 * Seguridad (spec §6): no sigue redirecciones (maxredirects=0), de modo que el
 * proveedor no puede desviar la petición a otro host (mitigación de SSRF). Solo
 * se invoca contra el endpoint LLM configurado; nunca contra URLs del contenido.
 * Verificado en el contenedor (la limitación del arnés impide instanciar el
 * core en el host, igual que TASK-003/004/005).
 */
final class LaminasHttpTransport implements HttpTransportInterface
{
    public function __construct(private int $timeout = 60)
    {
    }

    public function send(string $method, string $url, array $headers, string $body): HttpResult
    {
        $client = new HttpClient();
        $client->setUri($url);
        $client->setMethod(Request::METHOD_POST === strtoupper($method) ? Request::METHOD_POST : $method);
        $client->setOptions([
            'timeout' => $this->timeout,
            'maxredirects' => 0,
        ]);
        $client->setHeaders($headers);
        if ('' !== $body) {
            $client->setRawBody($body);
        }

        $response = $client->send();

        return new HttpResult($response->getStatusCode(), (string) $response->getBody());
    }
}
