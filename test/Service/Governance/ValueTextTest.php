<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\ValueText;
use PHPUnit\Framework\TestCase;

/**
 * Texto mostrable de un valor (ADR-0019). En el core, un valor URI sin etiqueta
 * se convierte en '' (`toString()` devuelve `value()`, que es la etiqueta): sin
 * esta pieza, un REA con licencia se veía como «Sin licencia».
 */
final class ValueTextTest extends TestCase
{
    public function testLabelWinsOverUri(): void
    {
        $this->assertSame(
            'Creative Commons Attribution 4.0 International',
            ValueText::of('Creative Commons Attribution 4.0 International', 'https://creativecommons.org/licenses/by/4.0/')
        );
    }

    public function testUriWithoutLabelShowsTheUri(): void
    {
        $this->assertSame(
            'https://creativecommons.org/licenses/by/4.0/',
            ValueText::of(null, 'https://creativecommons.org/licenses/by/4.0/')
        );
    }

    public function testBlankLabelFallsBackToUri(): void
    {
        $this->assertSame('https://example.org/l', ValueText::of("  \n", ' https://example.org/l '));
    }

    public function testLiteralWithoutUriIsItsOwnText(): void
    {
        $this->assertSame('CC BY', ValueText::of(' CC BY ', null));
    }

    public function testNothingIsEmpty(): void
    {
        $this->assertSame('', ValueText::of(null, null));
        $this->assertSame('', ValueText::of('', ''));
    }
}
