<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\GovernanceSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TDD de las claves y los parsers de gobernanza (ADR-0013, «sembrar, no poseer»).
 *
 * Los vocabularios y la plantilla se identifican SIEMPRE por id, nunca por
 * etiqueta: las etiquetas cambian con el idioma y con la edición del admin. El
 * parser devuelve `null` para «no configurado», que es la condición con la que el
 * campo degrada a texto libre en vez de romper (mismo patrón que CurriculumSearch,
 * que devuelve [] cuando su setting está vacío).
 */
final class GovernanceSettingsTest extends TestCase
{
    public function testKeysFollowTheModulePrefix(): void
    {
        $this->assertSame('oermanager_licence_vocab_id', GovernanceSettings::LICENCE_VOCAB_ID);
        $this->assertSame('oermanager_resource_type_vocab_id', GovernanceSettings::RESOURCE_TYPE_VOCAB_ID);
        $this->assertSame('oermanager_rea_template_id', GovernanceSettings::REA_TEMPLATE_ID);
        $this->assertSame('oermanager_default_rights_holder', GovernanceSettings::DEFAULT_RIGHTS_HOLDER);
    }

    /** @return array<string,array{0:mixed}> */
    public static function notConfiguredProvider(): array
    {
        return [
            'null' => [null],
            'cadena vacía' => [''],
            'solo espacios' => ['   '],
            'cero' => [0],
            'cero como texto' => ['0'],
            'negativo' => [-5],
            'no numérico' => ['la-de-licencias'],
            'array' => [[1]],
        ];
    }

    #[DataProvider('notConfiguredProvider')]
    public function testParseIdReturnsNullWhenNotConfigured(mixed $raw): void
    {
        $this->assertNull(GovernanceSettings::parseId($raw));
    }

    public function testParseIdAcceptsPositiveIntegers(): void
    {
        $this->assertSame(1, GovernanceSettings::parseId(1));
        $this->assertSame(40260, GovernanceSettings::parseId(40260));
    }

    public function testParseIdAcceptsNumericStringsFromThePostBody(): void
    {
        // El formulario devuelve todo como cadena.
        $this->assertSame(1, GovernanceSettings::parseId('1'));
        $this->assertSame(40260, GovernanceSettings::parseId(' 40260 '));
    }

    public function testParseRightsHolderTrimsAndKeepsText(): void
    {
        $this->assertSame(
            'Consejería de Educación del Gobierno de Canarias',
            GovernanceSettings::parseRightsHolder('  Consejería de Educación del Gobierno de Canarias  ')
        );
    }

    public function testParseRightsHolderReturnsEmptyStringWhenUnset(): void
    {
        $this->assertSame('', GovernanceSettings::parseRightsHolder(null));
        $this->assertSame('', GovernanceSettings::parseRightsHolder('   '));
    }

    public function testParseRightsHolderIsCappedToAvoidAbuse(): void
    {
        $capped = GovernanceSettings::parseRightsHolder(str_repeat('a', 500));

        $this->assertSame(GovernanceSettings::MAX_RIGHTS_HOLDER_LEN, mb_strlen($capped));
    }
}
