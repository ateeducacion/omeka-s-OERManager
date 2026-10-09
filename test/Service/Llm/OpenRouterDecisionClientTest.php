<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use OERManager\Service\Llm\HttpResult;
use OERManager\Service\Llm\LlmException;
use OERManager\Service\Llm\OpenRouterDecisionClient;
use PHPUnit\Framework\TestCase;

/**
 * TASK-062: Jev (typesafe/jev-1.13) through OpenRouter's decisions endpoint.
 * Fixtures are shaped as the real answers to the synthetic requests of
 * 2026-10-08: `noul` → P(yes); `choice` → option, probabilities, confidence;
 * `usage` with the charged cost and the served model.
 */
final class OpenRouterDecisionClientTest extends TestCase
{
    private const CONFIG = ['api_key' => 'k', 'model' => 'typesafe/jev-1.13', 'base_url' => 'https://openrouter.ai/api/v1'];

    private function ok(): HttpResult
    {
        return new HttpResult(200, (string) json_encode([
            'model' => 'typesafe/jev-1.13-20260917',
            'answers' => [
                'urgent' => ['type' => 'noul', 'noul' => 0.83],
                'team' => ['type' => 'choice', 'choice' => 'billing',
                    'probabilities' => ['billing' => 0.89, 'technical' => 0.11, 'none' => 0], 'confidence' => 0.83],
            ],
            'usage' => ['input_tokens' => 353, 'output_tokens' => 54, 'cost' => 0.000014826],
            'id' => 'gen-dec-1',
            'provider' => 'TypeSafe',
        ]));
    }

    /** @return array<string,array<string,mixed>> */
    private function questions(): array
    {
        return [
            'urgent' => ['type' => 'noul', 'instructions' => 'Does the ticket convey urgency?'],
            'team' => ['type' => 'choice', 'instructions' => 'Which team?',
                'criteria' => ['billing' => 'Payments', 'technical' => 'Bugs', 'none' => 'None of the above']],
        ];
    }

    public function testSendsJevsNativeBodyToTheDecisionsEndpoint(): void
    {
        $transport = new FakeTransport($this->ok());
        (new OpenRouterDecisionClient($transport, self::CONFIG))->decide('Ticket: payouts failing.', $this->questions());

        $this->assertSame('POST', $transport->method);
        $this->assertSame('https://openrouter.ai/api/alpha/decisions', $transport->url);
        $this->assertSame('Bearer k', $transport->headers['Authorization']);
        $this->assertSame(
            ['model' => 'typesafe/jev-1.13', 'state' => 'Ticket: payouts failing.', 'questions' => $this->questions()],
            $transport->decodedBody()
        );
    }

    /**
     * Numeric question ids ("0", "1") become a JSON list under json_encode, which
     * Jev rejects; found against the real endpoint on 2026-10-08. Questions must
     * always travel as a JSON object.
     */
    public function testQuestionsAlwaysTravelAsAJsonObject(): void
    {
        $transport = new FakeTransport($this->ok());
        (new OpenRouterDecisionClient($transport, self::CONFIG))
            ->decide('x', ['0' => ['type' => 'noul', 'instructions' => 'a'], '1' => ['type' => 'noul', 'instructions' => 'b']]);

        $this->assertStringContainsString('"questions":{"0":', (string) $transport->body);
    }

    public function testParsesTypedAnswersCostAndServedModel(): void
    {
        $result = (new OpenRouterDecisionClient(new FakeTransport($this->ok()), self::CONFIG))
            ->decide('x', $this->questions());

        $this->assertSame(0.83, $result->noul('urgent'));
        $this->assertSame('billing', $result->answer('team')['choice']);
        $this->assertSame(0.89, $result->answer('team')['probabilities']['billing']);
        $this->assertSame(353, $result->usage()->inputTokens());
        $this->assertSame(0.000014826, $result->usage()->cost());
        $this->assertSame('typesafe/jev-1.13-20260917', $result->usage()->model());
    }

    /** Untrusted answers: unknown ids, non-numeric or out-of-range probabilities are dropped, never trusted. */
    public function testMalformedAnswersAreDropped(): void
    {
        $body = (string) json_encode(['model' => 'm', 'answers' => [
            'urgent' => ['type' => 'noul', 'noul' => 'high'],
            'team' => ['type' => 'noul', 'noul' => 1.7],
            'injected' => ['type' => 'noul', 'noul' => 0.9],
        ], 'usage' => ['input_tokens' => 1]]);
        $result = (new OpenRouterDecisionClient(new FakeTransport(new HttpResult(200, $body)), self::CONFIG))
            ->decide('x', $this->questions());

        $this->assertNull($result->noul('urgent'));
        $this->assertNull($result->noul('team'));
        $this->assertNull($result->answer('injected'));
        $this->assertNull($result->usage()->cost());
    }

    public function testProviderErrorsBecomeLlmExceptions(): void
    {
        $this->expectException(LlmException::class);
        $body = (string) json_encode(['error' => ['message' => 'quota exceeded', 'code' => 402]]);
        (new OpenRouterDecisionClient(new FakeTransport(new HttpResult(402, $body)), self::CONFIG))
            ->decide('x', $this->questions());
    }

    public function testAnErrorInsideA200IsAnErrorToo(): void
    {
        $this->expectException(LlmException::class);
        $body = (string) json_encode(['error' => ['message' => 'bad request']]);
        (new OpenRouterDecisionClient(new FakeTransport(new HttpResult(200, $body)), self::CONFIG))
            ->decide('x', $this->questions());
    }

    /** The endpoint is derived from an OpenRouter-style base URL, never guessed for another provider. */
    public function testABaseUrlThatIsNotOpenRoutersApiIsAConfigurationError(): void
    {
        $this->expectException(LlmException::class);
        (new OpenRouterDecisionClient(new FakeTransport($this->ok()), ['base_url' => 'http://localhost:8080/v1'] + self::CONFIG))
            ->decide('x', $this->questions());
    }
}
