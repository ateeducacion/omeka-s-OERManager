<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\StoredProposal;
use PHPUnit\Framework\TestCase;

final class StoredProposalTest extends TestCase
{
    private static function proposal(): array
    {
        return [
            'created' => '2026-11-25T10:31:07+00:00',
            'model' => 'claude-test',
            'item_modified' => '2026-11-20T08:00:00+00:00',
            'alignment' => ['lrmi:teaches' => [123, 456], 'dcterms:relation' => [42]],
            'justifications' => ['lrmi:teaches' => [123 => 'Porque sí']],
        ];
    }

    public function testARoundTripKeepsTheProposal(): void
    {
        $text = StoredProposal::encode(self::proposal());
        $this->assertStringStartsWith('OERManager/ai-proposal/1 {"v":1,', $text);
        $this->assertTrue(StoredProposal::isProposal($text));
        $this->assertSame(self::proposal(), StoredProposal::decode($text));
    }

    public function testAnEmptyProposalStaysAnObjectAndRoundTrips(): void
    {
        $empty = array_merge(self::proposal(), ['alignment' => [], 'justifications' => []]);
        $text = StoredProposal::encode($empty);
        $this->assertStringContainsString('"alignment":{}', $text);
        $this->assertStringContainsString('"justifications":{}', $text);
        $this->assertSame($empty, StoredProposal::decode($text));
    }

    public function testIdsAreCleaned(): void
    {
        $dirty = self::proposal();
        $dirty['alignment'] = ['lrmi:teaches' => [5, '5', 0, -1, 'x', 7], 'lrmi:assesses' => []];
        $this->assertSame(['lrmi:teaches' => [5, 7]], StoredProposal::decode(StoredProposal::encode($dirty))['alignment']);
    }

    public function testWhatIsNotAReadableProposalDecodesToNull(): void
    {
        foreach (
            [
            '',
            'Una nota cualquiera',
            'OERManager/ai-proposal/1',
            'OERManager/ai-proposal/1 {roto',
            'OERManager/ai-proposal/1 []',
            'OERManager/ai-proposal/1 {"v":2,"created":"x","alignment":{}}',
            'OERManager/ai-proposal/1 {"v":1,"alignment":{}}',
            'OERManager/ai-proposal/1 {"v":1,"created":"x","alignment":"no"}',
            ] as $text
        ) {
            $this->assertNull(StoredProposal::decode($text), $text);
        }
        $this->assertFalse(StoredProposal::isProposal('Una nota'));
        $this->assertFalse(StoredProposal::isProposal('OERManager/ai-proposal/1'));
        $this->assertTrue(StoredProposal::isProposal('OERManager/ai-proposal/1 {roto'));
    }

    public function testFromPayloadKeepsTheIdsOfTheRunnerChipsAndDropsTheDebug(): void
    {
        $payload = [
            'alignment' => [
                'lrmi:teaches' => [['id' => 123, 'title' => 'A'], ['id' => 456, 'title' => 'B']],
                'dcterms:relation' => [['id' => 42, 'title' => 'Eje']],
            ],
            'justifications' => ['lrmi:teaches' => [123 => 'Porque sí']],
            'content' => ['truncated' => false],
            'debug' => ['content_text' => str_repeat('x', 1000)],
        ];
        $proposal = StoredProposal::fromPayload(
            $payload,
            'claude-test',
            new \DateTimeImmutable('2026-11-25T10:31:07+00:00'),
            new \DateTimeImmutable('2026-11-20T08:00:00+00:00')
        );
        $this->assertSame(self::proposal(), $proposal);
        $this->assertStringNotContainsString('content_text', StoredProposal::encode($proposal));
    }

    public function testChangedComparesTheItemModificationDate(): void
    {
        $proposal = self::proposal();
        $this->assertFalse(StoredProposal::changed($proposal, new \DateTimeImmutable('2026-11-20T08:00:00+00:00')));
        $this->assertTrue(StoredProposal::changed($proposal, new \DateTimeImmutable('2026-11-21T08:00:00+00:00')));
        $this->assertFalse(StoredProposal::changed($proposal, null));
        $proposal['item_modified'] = null;
        $this->assertTrue(StoredProposal::changed($proposal, new \DateTimeImmutable('2026-11-21T08:00:00+00:00')));
    }

    public function testFirstReadableAndAnyMarked(): void
    {
        $text = StoredProposal::encode(self::proposal());
        $this->assertSame(self::proposal(), StoredProposal::firstOf(['otra cosa', 'OERManager/ai-proposal/1 {roto', $text]));
        $this->assertNull(StoredProposal::firstOf(['otra cosa']));
        $this->assertTrue(StoredProposal::anyMarked(['x', 'OERManager/ai-proposal/1 {roto']));
        $this->assertFalse(StoredProposal::anyMarked(['x']));
    }

    public function testSearchRowMatchesTheMarker(): void
    {
        $this->assertSame(
            ['property' => 'curation:data', 'type' => 'sw', 'text' => 'OERManager/ai-proposal/1'],
            StoredProposal::searchRow('sw')
        );
    }

    public function testForPanelResolvesTitlesAndDropsTermsThatAreGone(): void
    {
        $titles = [123 => 'Saber A', 42 => 'Eje'];
        $view = StoredProposal::forPanel(
            self::proposal(),
            new \DateTimeImmutable('2026-11-21T08:00:00+00:00'),
            static fn (int $id): ?string => $titles[$id] ?? null
        );
        $this->assertSame([
            'created' => '2026-11-25T10:31:07+00:00',
            'changed' => true,
            'alignment' => [
                'lrmi:teaches' => [['id' => 123, 'title' => 'Saber A']],
                'dcterms:relation' => [['id' => 42, 'title' => 'Eje']],
            ],
            'justifications' => ['lrmi:teaches' => [123 => 'Porque sí']],
        ], $view);
    }
}
