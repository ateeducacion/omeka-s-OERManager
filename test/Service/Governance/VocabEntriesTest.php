<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\VocabEntries;
use PHPUnit\Framework\TestCase;

final class VocabEntriesTest extends TestCase
{
    public function testReadsEntriesOnceAndExposesUris(): void
    {
        $calls = 0;
        $vocab = new VocabEntries(2, function (int $id) use (&$calls): array {
            $calls++;
            return [['uri' => 'https://x/by/4.0/', 'label' => 'CC BY 4.0']];
        });

        $this->assertSame(['https://x/by/4.0/'], $vocab->uris());
        $this->assertSame('customvocab:2', $vocab->dataType());
        $this->assertTrue($vocab->isAvailable());
        $vocab->uris();
        $this->assertSame(1, $calls);
    }

    public function testUnsetSettingMeansUnresolvedNotEmpty(): void
    {
        $vocab = new VocabEntries(null, static fn (int $id): array => []);

        $this->assertNull($vocab->uris());
        $this->assertNull($vocab->dataType());
        $this->assertFalse($vocab->isAvailable());
    }

    public function testAReaderThatThrowsDegradesInsteadOfBreaking(): void
    {
        $vocab = new VocabEntries(9, static function (int $id): array {
            throw new \RuntimeException('CustomVocab is not active');
        });

        $this->assertNull($vocab->uris());
        $this->assertFalse($vocab->isAvailable());
    }
}
