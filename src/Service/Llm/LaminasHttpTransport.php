<?php

namespace OERManager\Service\Llm;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\Client\Adapter\Curl as CurlAdapter;
use Laminas\Http\Client\Adapter\Exception\ExceptionInterface as AdapterException;
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
 *
 * TOPE DE TIEMPO REAL (TASK-026): el adaptador por defecto (Socket) aplica
 * `timeout` con `stream_set_timeout()`, que es un tope de INACTIVIDAD por lectura,
 * no de la llamada entera. Un proveedor que emite tráfico de mantenimiento
 * mientras procesa nunca lo agota: un propose se quedó 10 min 19 s en una sola
 * llamada —el usuario lo vio como un cuelgue— y terminó devolviendo vacío. Curl
 * sí acota la llamada completa (CURLOPT_TIMEOUT), así que se usa cuando ext-curl
 * está disponible y se cae a Socket (degradado, mismo valor nominal) si no lo está.
 */
final class LaminasHttpTransport implements HttpTransportInterface
{
    /** Tope de la llamada COMPLETA. Un propose sano encadena 7-9 llamadas de ~2 s. */
    public const DEFAULT_TIMEOUT = 120;
    private const CONNECT_TIMEOUT = 15;

    public function __construct(private int $timeout = self::DEFAULT_TIMEOUT)
    {
    }

    public function send(string $method, string $url, array $headers, string $body): HttpResult
    {
        $client = new HttpClient();
        $client->setUri($url);
        $client->setMethod(Request::METHOD_POST === strtoupper($method) ? Request::METHOD_POST : $method);
        $client->setOptions($this->options());
        $client->setHeaders($headers);
        if ('' !== $body) {
            $client->setRawBody($body);
        }

        try {
            $response = $client->send();
        } catch (AdapterException $e) {
            // Agotar el tope no es un fallo silencioso: se traduce a la excepción
            // del dominio para que el Job lo registre y el panel lo diga.
            throw new LlmException(sprintf(
                'El proveedor LLM no respondió dentro del tope de %d s.',
                $this->timeout
            ));
        }

        return new HttpResult($response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array<string,mixed> */
    private function options(): array
    {
        $options = [
            'timeout' => $this->timeout,
            'maxredirects' => 0,
        ];
        if (!extension_loaded('curl')) {
            return $options;
        }
        $options['adapter'] = CurlAdapter::class;
        $options['curloptions'] = [
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // Coherente con maxredirects=0: el proveedor no redirige la petición.
            CURLOPT_FOLLOWLOCATION => false,
        ];
        return $options;
    }
}
