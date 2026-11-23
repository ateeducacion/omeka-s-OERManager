<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CustomVocabTerms;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

final class CustomVocabTermsTest extends TestCase
{
    private function reader(?string $type, ?array $terms): CustomVocabTerms
    {
        $vocab = new class ($type, $terms) {
            public function __construct(private ?string $type, private ?array $terms)
            {
            }

            public function type(): ?string
            {
                return $this->type;
            }

            public function listTerms(): ?array
            {
                return null === $this->terms ? null : array_combine($this->terms, $this->terms);
            }
        };
        $api = $this->createMock(Manager::class);
        $api->method('read')->with('custom_vocabs', 41)->willReturn(new Response($vocab));
        return new CustomVocabTerms($api);
    }

    public function testATermVocabularyGivesItsTerms(): void
    {
        $this->assertSame(
            [['value' => 'Otro motivo'], ['value' => 'Duplica un REA existente']],
            ($this->reader('literal', ['Otro motivo', 'Duplica un REA existente']))(41)
        );
    }

    public function testUriAndItemSetVocabulariesGiveNoTerms(): void
    {
        $this->assertSame([], ($this->reader('uri', null))(41));
        $this->assertSame([], ($this->reader('resource', null))(41));
        $this->assertSame([], ($this->reader('literal', null))(41));
    }
}
