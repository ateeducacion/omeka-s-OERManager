<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\ItemContext;
use PHPUnit\Framework\TestCase;

/**
 * TDD del contexto estructurado del item (ADR-0011): separa metadatos, contenido
 * de medios, descripciones de visión y ficha, y compone el texto por tipo de paso
 * (grueso vs fino) protegiendo el contenido de medios en el orden.
 */
final class ItemContextTest extends TestCase
{
    public function testCoarseTextIsTheFichaWhenPresent(): void
    {
        $ctx = (new ItemContext('META', 'MEDIA'))->withFicha('FICHA fiel');
        $coarse = $ctx->coarseText();
        $this->assertStringContainsString('FICHA fiel', $coarse);
        // El paso grueso NO arrastra el crudo de medios (barato).
        $this->assertStringNotContainsString('MEDIA', $coarse);
    }

    public function testCoarseTextFallsBackToMetadataWhenNoFicha(): void
    {
        $ctx = new ItemContext('META del item', 'MEDIA');
        $coarse = $ctx->coarseText();
        $this->assertStringContainsString('META del item', $coarse);
        $this->assertStringNotContainsString('MEDIA', $coarse);
    }

    public function testFineTextHasFichaMediaAndMetadataWithMediaBeforeMetadata(): void
    {
        $ctx = (new ItemContext('METADATOS', 'TEXTO_DEL_MEDIO'))->withFicha('FICHA');
        $fine = $ctx->fineText();
        $this->assertStringContainsString('FICHA', $fine);
        $this->assertStringContainsString('TEXTO_DEL_MEDIO', $fine);
        $this->assertStringContainsString('METADATOS', $fine);
        // P2: el contenido del medio va antes que los metadatos (no lo entierran ni
        // lo descarta el truncado por presupuesto si se aplicara aguas abajo).
        $this->assertLessThan(
            strpos($fine, 'METADATOS'),
            strpos($fine, 'TEXTO_DEL_MEDIO')
        );
    }

    public function testFineTextIncludesVisionDescriptions(): void
    {
        $ctx = new ItemContext('M', 'X', '', ['Infografía: la célula y sus partes']);
        $this->assertStringContainsString('la célula y sus partes', $ctx->fineText());
    }

    public function testWithFichaIsImmutable(): void
    {
        $base = new ItemContext('M', 'X');
        $withFicha = $base->withFicha('F');
        $this->assertStringNotContainsString('F', $base->coarseText());
        $this->assertStringContainsString('F', $withFicha->coarseText());
    }

    public function testIsEmptyWhenNoSignalAtAll(): void
    {
        $this->assertTrue((new ItemContext('', ''))->isEmpty());
        $this->assertFalse((new ItemContext('algo', ''))->isEmpty());
    }
}
