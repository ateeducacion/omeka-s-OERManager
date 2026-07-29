<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ResourceTypeVocab;
use PHPUnit\Framework\TestCase;

/**
 * Degradación explícita del vocabulario de tipos (ADR-0013): si el setting está
 * vacío, CustomVocab no está activo o el vocabulario ya no existe, el campo cae
 * a texto libre y la UI lo dice. Mismo patrón que CurriculumSearch, que
 * devuelve [] cuando su setting está sin configurar.
 */
final class ResourceTypeVocabTest extends TestCase
{
    public function testDegradesWhenTheSettingIsEmpty(): void
    {
        $vocab = new ResourceTypeVocab(null, static fn (int $id): array => ['A']);

        $this->assertFalse($vocab->isAvailable());
        $this->assertSame([], $vocab->values());
    }

    public function testReturnsTheVocabularyValues(): void
    {
        $vocab = new ResourceTypeVocab(1, static fn (int $id): array => ['Vídeo', 'Ficha']);

        $this->assertTrue($vocab->isAvailable());
        $this->assertSame(['Vídeo', 'Ficha'], $vocab->values());
    }

    public function testDegradesWhenTheVocabularyNoLongerExists(): void
    {
        $vocab = new ResourceTypeVocab(99, static function (int $id): array {
            throw new \RuntimeException('not found');
        });

        $this->assertFalse($vocab->isAvailable());
        $this->assertSame([], $vocab->values());
    }

    public function testDegradesWhenTheVocabularyIsEmpty(): void
    {
        $vocab = new ResourceTypeVocab(1, static fn (int $id): array => []);

        $this->assertFalse($vocab->isAvailable());
    }

    public function testTheReaderIsCalledOnlyOnce(): void
    {
        $calls = 0;
        $vocab = new ResourceTypeVocab(1, static function (int $id) use (&$calls): array {
            $calls++;
            return ['Vídeo'];
        });

        $vocab->values();
        $vocab->values();
        $vocab->isAvailable();

        $this->assertSame(1, $calls);
    }
}
