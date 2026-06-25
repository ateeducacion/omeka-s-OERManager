<?php

namespace OERManager\Service\Content;

/**
 * Resultado inmutable de la extracción de contenido de un item: el texto
 * (metadatos + medios, ya saneado y truncado al presupuesto), si se truncó, las
 * fuentes leídas y las saltadas con su motivo (auditoría de la extracción).
 */
final class ExtractedContent
{
    /**
     * @param string[] $sources nombres de fuentes leídas con éxito
     * @param array<string,string> $skipped nombre => motivo del salto
     */
    public function __construct(
        private string $text,
        private bool $truncated,
        private array $sources = [],
        private array $skipped = []
    ) {
    }

    public function text(): string
    {
        return $this->text;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return string[]
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return array<string,string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->text);
    }
}
