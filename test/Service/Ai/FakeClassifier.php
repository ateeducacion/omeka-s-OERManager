<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\ClassifierInterface;
use OERManager\Service\Content\ItemContext;

/**
 * Clasificador falso: registra el texto fino del contexto recibido y devuelve un
 * mapa fijo, para TDD del orquestador AiCataloguer.
 */
final class FakeClassifier implements ClassifierInterface
{
    /** @var array<string,int[]> */
    private array $result;
    /** @var array<string,array<int,string>> */
    private array $justifications;
    /** @var string[] */
    public array $received = [];

    /**
     * @param array<string,int[]> $result
     * @param array<string,array<int,string>> $justifications
     */
    public function __construct(array $result = [], array $justifications = [])
    {
        $this->result = $result;
        $this->justifications = $justifications;
    }

    public function classify(ItemContext $context): array
    {
        $this->received[] = $context->fineText();
        return $this->result;
    }

    /** @return array<string,array<int,string>> */
    public function getJustifications(): array
    {
        return $this->justifications;
    }
}
