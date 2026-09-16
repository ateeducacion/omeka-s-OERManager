<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\LicenceStatus;
use PHPUnit\Framework\TestCase;

final class LicenceStatusTest extends TestCase
{
    private const VOCAB = ['https://creativecommons.org/licenses/by-sa/4.0/', 'https://creativecommons.org/licenses/by/4.0/'];

    public function testNoValuesIsMissing(): void
    {
        $this->assertSame(LicenceStatus::MISSING, LicenceStatus::of([], self::VOCAB));
    }

    public function testUriInTheVocabulary(): void
    {
        $values = [['type' => 'customvocab:2', 'uri' => 'https://creativecommons.org/licenses/by/4.0/']];

        $this->assertSame(LicenceStatus::IN_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }

    public function testUriOutsideTheVocabulary(): void
    {
        $values = [['type' => 'uri', 'uri' => 'https://example.org/licencia-propia']];

        $this->assertSame(LicenceStatus::OUTSIDE_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }

    public function testLiteralWithoutUriIsOutsideTheVocabulary(): void
    {
        $values = [['type' => 'literal', 'value' => 'ccbysa']];

        $this->assertSame(LicenceStatus::OUTSIDE_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }

    public function testWithoutAConfiguredVocabularyMembershipIsNotJudged(): void
    {
        $values = [['type' => 'uri', 'uri' => 'https://example.org/licencia-propia']];

        $this->assertSame(LicenceStatus::UNCHECKED, LicenceStatus::of($values, null));
        $this->assertSame(LicenceStatus::MISSING, LicenceStatus::of([], null));
    }

    public function testTrailingSlashAndCaseOfHostDoNotChangeMembership(): void
    {
        $values = [['type' => 'uri', 'uri' => 'https://CreativeCommons.org/licenses/by/4.0']];

        $this->assertSame(LicenceStatus::IN_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }
}
