<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Governance\VocabEntries;
use OERManager\Service\Workflow\ReasonVocabularies;
use PHPUnit\Framework\TestCase;

final class ReasonVocabulariesTest extends TestCase
{
    private function vocabularies(?array $reject, ?array $discard = null): ReasonVocabularies
    {
        $entries = static fn (?array $terms): VocabEntries => new VocabEntries(
            null === $terms ? null : 41,
            static fn (int $id): array => $terms ?? []
        );
        return new ReasonVocabularies($entries($reject), $entries($discard));
    }

    public function testWithoutAVocabularyTheReasonIsFreeTextAndTheDetailIsDropped(): void
    {
        $vocabularies = $this->vocabularies(null);
        $this->assertNull($vocabularies->terms(ReasonVocabularies::REJECT));
        $reason = $vocabularies->resolve(ReasonVocabularies::REJECT, ' Falta la licencia ', 'detalle')['reason'];
        $this->assertSame('Falta la licencia', $reason->text);
        $this->assertNull($reason->dataType);
        $this->assertSame('', $reason->detail);
    }

    public function testAnEmptyReasonIsRequiredWithOrWithoutAVocabulary(): void
    {
        $this->assertSame(['error' => 'reason_required'], $this->vocabularies(null)->resolve('reject', '  ', 'x'));
        $this->assertSame(
            ['error' => 'reason_required'],
            $this->vocabularies([['value' => 'Otro motivo']])->resolve('reject', '', 'x')
        );
    }

    public function testAVocabularyOffersItsTermsWithoutBlanksOrDuplicates(): void
    {
        $vocabularies = $this->vocabularies([['value' => 'Otro motivo'], ['value' => ' '], ['value' => 'Otro motivo'],
            ['value' => 'Accesibilidad insuficiente']]);
        $this->assertSame(['Otro motivo', 'Accesibilidad insuficiente'], $vocabularies->terms('reject'));
    }

    public function testOnlyATermIsAcceptedTrimmedButCaseSensitive(): void
    {
        $vocabularies = $this->vocabularies([['value' => 'Otro motivo']], [['value' => 'Duplica un REA existente']]);
        $this->assertSame(['error' => 'reason_invalid'], $vocabularies->resolve('reject', 'texto libre', ''));
        $this->assertSame(['error' => 'reason_invalid'], $vocabularies->resolve('reject', 'otro motivo', ''));
        // Each action has its own list.
        $this->assertSame(['error' => 'reason_invalid'], $vocabularies->resolve('discard', 'Otro motivo', ''));
        $reason = $vocabularies->resolve('reject', ' Otro motivo ', ' el vídeo 2 ')['reason'];
        $this->assertSame('Otro motivo', $reason->text);
        $this->assertSame('customvocab:41', $reason->dataType);
        $this->assertSame('el vídeo 2', $reason->detail);
    }

    public function testUriEntriesAnEmptyListOrAFailingReaderMeanFreeText(): void
    {
        $this->assertNull($this->vocabularies([['uri' => 'https://example.org/x']])->terms('reject'));
        $this->assertNull($this->vocabularies([])->terms('reject'));
        $failing = new ReasonVocabularies(
            new VocabEntries(41, static function (): array {
                throw new \RuntimeException('CustomVocab is not active');
            }),
            new VocabEntries(null, static fn (): array => [])
        );
        $this->assertNull($failing->terms('reject'));
        $this->assertNull($failing->terms('unpublish'));
        $this->assertNull($failing->resolve('unpublish', 'Licencia dudosa', '')['reason']->dataType);
    }
}
