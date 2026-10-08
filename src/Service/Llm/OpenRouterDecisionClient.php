<?php

declare(strict_types=1);

namespace OERManager\Service\Llm;

/**
 * Jev (TypeSafe) a través de OpenRouter (TASK-062): `POST …/api/alpha/decisions`
 * con el cuerpo nativo de Jev (`model`, `state`, `questions`). Comprobado con
 * peticiones sintéticas el 2026-10-08: el endpoint de chat rechaza este modelo,
 * y este devuelve respuestas tipadas más `usage` con el coste cargado.
 *
 * La ruta es «alpha»: puede cambiar. La respuesta es dato no confiable: se
 * descartan ids no preguntados, tipos distintos del pedido y probabilidades
 * no numéricas o fuera de [0, 1].
 */
final class OpenRouterDecisionClient implements DecisionModelInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    /**
     * @param array<string,mixed> $config 'api_key', 'model', 'base_url' (la de OpenRouter, …/api/v1)
     */
    public function __construct(private HttpTransportInterface $transport, array $config = [])
    {
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->model = trim((string) ($config['model'] ?? ''));
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    }

    public function decide(string $state, array $questions): DecisionResult
    {
        if ('' === $this->model) {
            throw new LlmException('Modelo de decisiones no configurado.');
        }
        $result = $this->transport->send(
            'POST',
            $this->endpoint(),
            ['Authorization' => 'Bearer ' . $this->apiKey, 'content-type' => 'application/json'],
            // `questions` siempre como objeto: con ids numéricos json_encode daría una lista.
            (string) json_encode(['model' => $this->model, 'state' => $state, 'questions' => (object) $questions])
        );
        $data = json_decode($result->body(), true);
        if (!$result->isSuccess() || !is_array($data) || isset($data['error'])) {
            throw new LlmException(sprintf(
                'El modelo de decisiones devolvió HTTP %d: %s',
                $result->status(),
                $this->safeError($data)
            ));
        }

        $answers = [];
        foreach ($questions as $id => $question) {
            $answer = $this->validAnswer($data['answers'][$id] ?? null, (string) ($question['type'] ?? ''));
            if (null !== $answer) {
                $answers[(string) $id] = $answer;
            }
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        return new DecisionResult($answers, new ChatResult(
            '',
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            is_numeric($usage['cost'] ?? null) ? (float) $usage['cost'] : null,
            is_string($data['model'] ?? null) && '' !== $data['model'] ? $data['model'] : $this->model
        ));
    }

    /** `https://openrouter.ai/api/v1` → `https://openrouter.ai/api/alpha/decisions`; nada más se adivina. */
    private function endpoint(): string
    {
        if (1 !== preg_match('#^(https://[^/]+/api)/v1$#', $this->baseUrl, $m)) {
            throw new LlmException('El modelo de decisiones necesita la base URL de OpenRouter (…/api/v1).');
        }
        return $m[1] . '/alpha/decisions';
    }

    /** @return array<string,mixed>|null */
    private function validAnswer(mixed $answer, string $type): ?array
    {
        if (!is_array($answer) || ($answer['type'] ?? null) !== $type) {
            return null;
        }
        $probability = static fn (mixed $p): bool => is_numeric($p) && (float) $p >= 0.0 && (float) $p <= 1.0;
        if ('noul' === $type) {
            return $probability($answer['noul'] ?? null) ? ['type' => 'noul', 'noul' => (float) $answer['noul']] : null;
        }
        if ('choice' === $type && is_string($answer['choice'] ?? null) && is_array($answer['probabilities'] ?? null)) {
            $probabilities = array_filter($answer['probabilities'], $probability);
            return [
                'type' => 'choice',
                'choice' => $answer['choice'],
                'probabilities' => array_map('floatval', $probabilities),
                'confidence' => $probability($answer['confidence'] ?? null) ? (float) $answer['confidence'] : null,
            ];
        }
        return null;
    }

    private function safeError(mixed $data): string
    {
        $error = is_array($data) ? ($data['error'] ?? '') : '';
        $message = is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
        return mb_substr('' === $message ? 'error del proveedor' : $message, 0, 200);
    }
}
