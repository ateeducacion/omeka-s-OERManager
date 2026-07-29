<?php

namespace OERManager\Service;

/**
 * Lector del CustomVocab de tipos de recurso (ADR-0013, «sembrar, no poseer»).
 *
 * El vocabulario NO lo crea el módulo: ya existe y se identifica por el setting
 * `oermanager_resource_type_vocab_id`, nunca por etiqueta. Si el setting está
 * vacío, CustomVocab no está activo o el vocabulario desapareció, degrada a
 * lista vacía y el filtro cae a texto libre diciéndolo.
 *
 * El lector se inyecta como callable para que el núcleo sea testeable en host
 * sin el core de Omeka.
 */
class ResourceTypeVocab
{
    private ?int $vocabId;
    /** @var callable(int):array<string> */
    private $reader;
    private ?array $values = null;

    public function __construct(?int $vocabId, callable $reader)
    {
        $this->vocabId = $vocabId;
        $this->reader = $reader;
    }

    /** @return array<string> */
    public function values(): array
    {
        if (null !== $this->values) {
            return $this->values;
        }
        if (null === $this->vocabId || $this->vocabId <= 0) {
            $this->values = [];
            return $this->values;
        }
        try {
            $this->values = array_values(array_filter(
                array_map('strval', ($this->reader)($this->vocabId)),
                static fn (string $value): bool => '' !== trim($value)
            ));
        } catch (\Throwable $e) {
            // El vocabulario ya no existe o CustomVocab no está activo: degradar,
            // no romper la vista maestra entera.
            $this->values = [];
        }
        return $this->values;
    }

    public function isAvailable(): bool
    {
        return [] !== $this->values();
    }
}
