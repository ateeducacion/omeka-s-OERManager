<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use OERManager\Service\Llm\AnthropicClient;
use OERManager\Service\Llm\ChatResult;
use OERManager\Service\Llm\HttpResult;
use OERManager\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;

/**
 * TDD del adaptador Anthropic (Messages API, ADR-0008). Construcción del cuerpo,
 * cabeceras, parseo de texto+uso y manejo de error, con transporte falso.
 */
final class AnthropicClientTest extends TestCase
{
    private function okResult(string $text = 'hola', int $in = 10, int $out = 5): HttpResult
    {
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
            'stop_reason' => 'end_turn',
            'model' => 'claude-opus-4-8',
        ]);
        return new HttpResult(200, (string) $body);
    }

    public function testBuildsMessagesApiRequest(): void
    {
        $transport = new FakeTransport($this->okResult());
        $client = new AnthropicClient($transport, [
            'api_key' => 'sk-secret',
            'model' => 'claude-opus-4-8',
            'base_url' => 'https://api.anthropic.com',
        ]);

        $client->chat(
            [['role' => 'user', 'content' => 'clasifica esto']],
            ['system' => 'Eres un clasificador', 'max_tokens' => 512]
        );

        $this->assertSame('POST', $transport->method);
        $this->assertSame('https://api.anthropic.com/v1/messages', $transport->url);
        $this->assertSame('sk-secret', $transport->headers['x-api-key'] ?? null);
        $this->assertSame('2023-06-01', $transport->headers['anthropic-version'] ?? null);
        $this->assertSame('application/json', $transport->headers['content-type'] ?? null);

        $body = $transport->decodedBody();
        $this->assertSame('claude-opus-4-8', $body['model']);
        $this->assertSame(512, $body['max_tokens']);
        $this->assertSame('Eres un clasificador', $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'clasifica esto']], $body['messages']);
    }

    public function testTranslatesMultimodalContentPartsToAnthropicBlocks(): void
    {
        // El contenido como lista de partes neutrales (texto/imagen/documento) se
        // traduce a los bloques que espera la Messages API (ADR-0011, visión/PDF).
        $transport = new FakeTransport($this->okResult());
        $client = new AnthropicClient($transport, ['api_key' => 'k', 'model' => 'm']);
        $client->chat([[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'describe'],
                ['type' => 'image', 'media_type' => 'image/png', 'data' => 'BASE64IMG'],
                ['type' => 'document', 'media_type' => 'application/pdf', 'data' => 'BASE64PDF'],
            ],
        ]]);

        $this->assertSame([
            ['type' => 'text', 'text' => 'describe'],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'BASE64IMG']],
            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'BASE64PDF']],
        ], $transport->decodedBody()['messages'][0]['content']);
    }

    public function testReportsImageAndPdfCapabilities(): void
    {
        $client = new AnthropicClient(new FakeTransport($this->okResult()), ['api_key' => 'k', 'model' => 'm']);
        $this->assertTrue($client->supportsImages());
        $this->assertTrue($client->supportsPdf());
    }

    public function testDoesNotSendTemperatureByDefault(): void
    {
        // Opus 4.8 rechaza temperature con 400: no debe enviarse salvo petición explícita.
        $transport = new FakeTransport($this->okResult());
        $client = new AnthropicClient($transport, ['api_key' => 'k', 'model' => 'claude-opus-4-8']);
        $client->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertArrayNotHasKey('temperature', $transport->decodedBody());
    }

    public function testParsesTextAndTokenUsage(): void
    {
        $transport = new FakeTransport($this->okResult('{"about":["Matemáticas"]}', 12, 8));
        $client = new AnthropicClient($transport, ['api_key' => 'k', 'model' => 'm']);
        $result = $client->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertInstanceOf(ChatResult::class, $result);
        $this->assertSame('{"about":["Matemáticas"]}', $result->text());
        $this->assertSame(12, $result->inputTokens());
        $this->assertSame(8, $result->outputTokens());
        $this->assertSame(20, $result->totalTokens());
    }

    public function testConcatenatesTextBlocksAndIgnoresOthers(): void
    {
        $body = json_encode([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'razonando...'],
                ['type' => 'text', 'text' => 'parte A'],
                ['type' => 'text', 'text' => 'parte B'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]);
        $transport = new FakeTransport(new HttpResult(200, (string) $body));
        $client = new AnthropicClient($transport, ['api_key' => 'k', 'model' => 'm']);
        $this->assertSame('parte Aparte B', $client->chat([['role' => 'user', 'content' => 'x']])->text());
    }

    public function testThrowsOnErrorStatusWithoutLeakingApiKey(): void
    {
        $transport = new FakeTransport(new HttpResult(401, '{"error":{"message":"invalid"}}'));
        $client = new AnthropicClient($transport, ['api_key' => 'sk-super-secret', 'model' => 'm']);
        try {
            $client->chat([['role' => 'user', 'content' => 'x']]);
            $this->fail('Debió lanzar LlmException');
        } catch (LlmException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString('sk-super-secret', $e->getMessage());
        }
    }

    public function testThrowsWhenModelNotConfigured(): void
    {
        $transport = new FakeTransport($this->okResult());
        $client = new AnthropicClient($transport, ['api_key' => 'k', 'model' => '']);
        $this->expectException(LlmException::class);
        $client->chat([['role' => 'user', 'content' => 'x']]);
    }
}
