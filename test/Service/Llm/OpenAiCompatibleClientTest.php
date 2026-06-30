<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use OERManager\Service\Llm\HttpResult;
use OERManager\Service\Llm\LlmException;
use OERManager\Service\Llm\OpenAiCompatibleClient;
use PHPUnit\Framework\TestCase;

/**
 * TDD del adaptador OpenAI-compatible (chat/completions; cubre modelo local y
 * cualquier endpoint OpenAI-compatible, ADR-0008).
 */
final class OpenAiCompatibleClientTest extends TestCase
{
    private function okResult(string $text = 'hola', int $in = 7, int $out = 3): HttpResult
    {
        $body = json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ]);
        return new HttpResult(200, (string) $body);
    }

    public function testBuildsChatCompletionsRequest(): void
    {
        $transport = new FakeTransport($this->okResult());
        $client = new OpenAiCompatibleClient($transport, [
            'api_key' => 'sk-local',
            'model' => 'local-model',
            'base_url' => 'http://localhost:8080/v1',
        ]);

        $client->chat(
            [['role' => 'user', 'content' => 'clasifica']],
            ['system' => 'sistema', 'max_tokens' => 256, 'json' => true]
        );

        $this->assertSame('POST', $transport->method);
        $this->assertSame('http://localhost:8080/v1/chat/completions', $transport->url);
        $this->assertSame('Bearer sk-local', $transport->headers['Authorization'] ?? null);

        $body = $transport->decodedBody();
        $this->assertSame('local-model', $body['model']);
        $this->assertSame(256, $body['max_tokens']);
        $this->assertSame(
            [['role' => 'system', 'content' => 'sistema'], ['role' => 'user', 'content' => 'clasifica']],
            $body['messages']
        );
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
    }

    public function testNoJsonModeWhenNotRequested(): void
    {
        $transport = new FakeTransport($this->okResult());
        $client = new OpenAiCompatibleClient($transport, ['api_key' => 'k', 'model' => 'm', 'base_url' => 'http://x/v1']);
        $client->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertArrayNotHasKey('response_format', $transport->decodedBody());
    }

    public function testParsesChoiceAndUsage(): void
    {
        $transport = new FakeTransport($this->okResult('respuesta', 9, 4));
        $client = new OpenAiCompatibleClient($transport, ['api_key' => 'k', 'model' => 'm', 'base_url' => 'http://x/v1']);
        $result = $client->chat([['role' => 'user', 'content' => 'x']]);
        $this->assertSame('respuesta', $result->text());
        $this->assertSame(9, $result->inputTokens());
        $this->assertSame(4, $result->outputTokens());
    }

    public function testTranslatesImagePartToImageUrlAndSkipsDocument(): void
    {
        // Imágenes → image_url (data URL); el PDF no es representable aquí y se omite
        // (el gating de visión/PDF lo decide el extractor por capacidad, ADR-0011).
        $transport = new FakeTransport($this->okResult());
        $client = new OpenAiCompatibleClient($transport, ['api_key' => 'k', 'model' => 'm', 'base_url' => 'http://x/v1']);
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
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,BASE64IMG']],
        ], $transport->decodedBody()['messages'][0]['content']);
    }

    public function testReportsImageButNotPdfCapability(): void
    {
        $client = new OpenAiCompatibleClient(
            new FakeTransport($this->okResult()),
            ['api_key' => 'k', 'model' => 'm', 'base_url' => 'http://x/v1']
        );
        $this->assertTrue($client->supportsImages());
        $this->assertFalse($client->supportsPdf());
    }

    public function testThrowsOnErrorStatus(): void
    {
        $transport = new FakeTransport(new HttpResult(500, '{"error":"boom"}'));
        $client = new OpenAiCompatibleClient($transport, ['api_key' => 'k', 'model' => 'm']);
        $this->expectException(LlmException::class);
        $client->chat([['role' => 'user', 'content' => 'x']]);
    }
}
