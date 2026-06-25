<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\ClassifierInterface;

/**
 * Clasificador falso: registra el contenido recibido y devuelve un mapa fijo,
 * para TDD del orquestador AiCataloguer.
 */
final class FakeClassifier implements ClassifierInterface
{
    /** @var array<string,int[]> */
    private array $result;
    /** @var string[] */
    public array $received = [];

    /** @param array<string,int[]> $result */
    public function __construct(array $result = [])
    {
        $this->result = $result;
    }

    public function classify(string $content): array
    {
        $this->received[] = $content;
        return $this->result;
    }
}
