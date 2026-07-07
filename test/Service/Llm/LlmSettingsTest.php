<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Llm;

use OERManager\Service\Llm\LlmSettings;
use PHPUnit\Framework\TestCase;

/**
 * TDD de los parsers puros del perfil de inferencia (paridad entre proveedores):
 * temperatura vacía = NO enviar (los Opus 4.6+ la rechazan con 400); max_tokens
 * inválido cae al default. Puros: los usan las factorías y handleConfigForm.
 */
final class LlmSettingsTest extends TestCase
{
    public function testParseTemperatureEmptyOrNullMeansDoNotSend(): void
    {
        $this->assertNull(LlmSettings::parseTemperature(''));
        $this->assertNull(LlmSettings::parseTemperature('   '));
        $this->assertNull(LlmSettings::parseTemperature(null));
    }

    public function testParseTemperatureAcceptsNumericInRange(): void
    {
        $this->assertSame(0.0, LlmSettings::parseTemperature('0'));
        $this->assertSame(0.2, LlmSettings::parseTemperature('0.2'));
        $this->assertSame(1.0, LlmSettings::parseTemperature(1));
        $this->assertSame(2.0, LlmSettings::parseTemperature('2'));
    }

    public function testParseTemperatureRejectsNonNumericOrOutOfRange(): void
    {
        $this->assertNull(LlmSettings::parseTemperature('abc'));
        $this->assertNull(LlmSettings::parseTemperature('-0.1'));
        $this->assertNull(LlmSettings::parseTemperature('2.5'));
    }

    public function testParseMaxTokensDefaultsWhenEmptyOrInvalid(): void
    {
        $this->assertSame(LlmSettings::DEFAULT_MAX_TOKENS, LlmSettings::parseMaxTokens(''));
        $this->assertSame(LlmSettings::DEFAULT_MAX_TOKENS, LlmSettings::parseMaxTokens(null));
        $this->assertSame(LlmSettings::DEFAULT_MAX_TOKENS, LlmSettings::parseMaxTokens(0));
        $this->assertSame(LlmSettings::DEFAULT_MAX_TOKENS, LlmSettings::parseMaxTokens(-5));
        $this->assertSame(LlmSettings::DEFAULT_MAX_TOKENS, LlmSettings::parseMaxTokens('abc'));
    }

    public function testParseMaxTokensAcceptsPositiveInt(): void
    {
        $this->assertSame(4096, LlmSettings::parseMaxTokens('4096'));
        $this->assertSame(2048, LlmSettings::parseMaxTokens(2048));
    }
}
