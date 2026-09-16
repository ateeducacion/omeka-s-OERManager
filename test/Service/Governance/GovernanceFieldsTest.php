<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\GovernanceFields;
use PHPUnit\Framework\TestCase;

final class GovernanceFieldsTest extends TestCase
{
    public function testTrimsValuesAndDropsEmptyOnes(): void
    {
        $result = GovernanceFields::normalise(['dcterms:creator' => ['  Ana Pérez ', '   ', 'Luis Gil']]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            ['type' => 'literal', 'value' => 'Ana Pérez'],
            ['type' => 'literal', 'value' => 'Luis Gil'],
        ], $result['values']['dcterms:creator']);
    }

    public function testKeepsAuthorOrderAsEntered(): void
    {
        $result = GovernanceFields::normalise(['dcterms:creator' => ['Zoe', 'Ana']]);

        $this->assertSame(['Zoe', 'Ana'], array_column($result['values']['dcterms:creator'], 'value'));
    }

    public function testSingleValuedFieldRejectsASecondValue(): void
    {
        $result = GovernanceFields::normalise(['dcterms:rightsHolder' => ['Consejería', 'Otro']]);

        $this->assertSame('too-many', $result['errors']['dcterms:rightsHolder']);
        $this->assertArrayNotHasKey('dcterms:rightsHolder', $result['values']);
    }

    public function testSourceMustBeHttpUri(): void
    {
        $result = GovernanceFields::normalise(['dcterms:source' => ['ftp://example.org/x']]);

        $this->assertSame('not-http-uri', $result['errors']['dcterms:source']);
    }

    public function testSourceAcceptsHttpsAndKeepsItAsUri(): void
    {
        $result = GovernanceFields::normalise(['dcterms:source' => ['https://example.org/rea/7']]);

        $this->assertSame([['type' => 'uri', 'uri' => 'https://example.org/rea/7']], $result['values']['dcterms:source']);
    }

    public function testClearingAFieldIsAnEmptyListNotAnAbsentTerm(): void
    {
        $result = GovernanceFields::normalise(['dcterms:creator' => ['']]);

        $this->assertSame([], $result['values']['dcterms:creator']);
        $this->assertArrayNotHasKey('dcterms:license', $result['values']);
    }

    public function testUnknownTermsAreIgnored(): void
    {
        $result = GovernanceFields::normalise(['dcterms:title' => ['Hola']]);

        $this->assertSame([], $result['values']);
        $this->assertSame([], $result['errors']);
    }
}
