<?php

namespace OERManager\Service\Content;

/**
 * Contexto estructurado del item que se pasa al LLM (ADR-0011). Sustituye al blob
 * plano único: mantiene separados, con su procedencia, los metadatos, el texto de
 * los medios, las descripciones de visión y la ficha destilada, y compone el texto
 * adecuado a cada tipo de paso del clasificador.
 *
 * - Pasos GRUESOS (etapa, materia, bloque) → `coarseText()`: solo la ficha (barato);
 *   sin ficha, recae en los metadatos (sin arrastrar el crudo de medios).
 * - Pasos FINOS (saberes, criterios, ejes) → `fineText()`: ficha + contenido de
 *   medios + visión + metadatos, con el contenido de medios ANTES que los metadatos
 *   para que el truncado por presupuesto (si se aplica) no lo entierre (P2).
 *
 * Inmutable: `withFicha()` devuelve una copia (la ficha se calcula tras extraer).
 */
final class ItemContext
{
    /**
     * @param string[] $visionDescriptions descripciones de imágenes/PDF (visión)
     */
    public function __construct(
        private string $metadata,
        private string $mediaText,
        private string $ficha = '',
        private array $visionDescriptions = []
    ) {
    }

    public function withFicha(string $ficha): self
    {
        return new self($this->metadata, $this->mediaText, $ficha, $this->visionDescriptions);
    }

    /** Texto para pasos gruesos: la ficha, o los metadatos si aún no hay ficha. */
    public function coarseText(): string
    {
        if ('' !== trim($this->ficha)) {
            return $this->section('Ficha del recurso', $this->ficha);
        }
        return $this->compose([
            ['Metadatos del recurso', $this->metadata],
            ['Descripción visual', $this->visionText()],
        ]);
    }

    /** Texto para pasos finos: ficha + medios (protegidos) + visión + metadatos. */
    public function fineText(): string
    {
        return $this->compose([
            ['Ficha del recurso', $this->ficha],
            ['Contenido de los medios', $this->mediaText],
            ['Descripción visual', $this->visionText()],
            ['Metadatos del recurso', $this->metadata],
        ]);
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->metadata)
            && '' === trim($this->mediaText)
            && '' === trim($this->ficha)
            && '' === trim($this->visionText());
    }

    /** Crudo (metadatos + medios + visión) que consume el destilador. */
    public function rawForDistillation(): string
    {
        return $this->compose([
            ['Metadatos del recurso', $this->metadata],
            ['Contenido de los medios', $this->mediaText],
            ['Descripción visual', $this->visionText()],
        ]);
    }

    private function visionText(): string
    {
        $lines = array_filter(
            array_map('trim', $this->visionDescriptions),
            static fn (string $s): bool => '' !== $s
        );
        return implode("\n", $lines);
    }

    /**
     * @param array<int,array{0:string,1:string}> $sections [etiqueta, texto]
     */
    private function compose(array $sections): string
    {
        $parts = [];
        foreach ($sections as [$label, $text]) {
            if ('' !== trim($text)) {
                $parts[] = $this->section($label, $text);
            }
        }
        return implode("\n\n", $parts);
    }

    private function section(string $label, string $text): string
    {
        return '[' . $label . ']' . "\n" . trim($text);
    }
}
