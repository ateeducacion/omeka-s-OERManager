<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\StoredProposal;
use OERManager\Service\Ai\StoredProposalWriter;
use PHPUnit\Framework\TestCase;

final class StoredProposalWriterTest extends TestCase
{
    public static function property(string $term): object
    {
        [$prefix, $local] = explode(':', $term);
        return new class ($prefix, $local) {
            public function __construct(private string $prefix, private string $local)
            {
            }
            public function getVocabulary(): object
            {
                $prefix = $this->prefix;
                return new class ($prefix) {
                    public function __construct(private string $prefix)
                    {
                    }
                    public function getPrefix(): string
                    {
                        return $this->prefix;
                    }
                };
            }
            public function getLocalName(): string
            {
                return $this->local;
            }
        };
    }

    public static function newValue(): object
    {
        return new class {
            public $resource;
            public $property;
            public ?string $type = null;
            public ?string $value = null;
            public bool $public = true;
            public function setResource($resource): void
            {
                $this->resource = $resource;
            }
            public function setProperty($property): void
            {
                $this->property = $property;
            }
            public function getProperty()
            {
                return $this->property;
            }
            public function setType($type): void
            {
                $this->type = $type;
            }
            public function getType(): ?string
            {
                return $this->type;
            }
            public function setValue($value): void
            {
                $this->value = $value;
            }
            public function getValue(): ?string
            {
                return $this->value;
            }
            public function setIsPublic($public): void
            {
                $this->public = (bool) $public;
            }
            public function isPublic(): bool
            {
                return $this->public;
            }
        };
    }

    public static function value(string $term, string $text): object
    {
        $value = self::newValue();
        $value->setProperty(self::property($term));
        $value->setType('literal');
        $value->setValue($text);
        return $value;
    }

    public static function entity(array $values, ?\DateTimeInterface $modified = null): object
    {
        return new class ($values, $modified) {
            public object $values;
            public function __construct(array $values, private ?\DateTimeInterface $modified)
            {
                $this->values = new class ($values) implements \IteratorAggregate {
                    public function __construct(public array $items)
                    {
                    }
                    public function add($value): void
                    {
                        $this->items[] = $value;
                    }
                    public function removeElement($value): void
                    {
                        $this->items = array_values(array_filter($this->items, static fn ($i) => $i !== $value));
                    }
                    public function getIterator(): \ArrayIterator
                    {
                        return new \ArrayIterator($this->items);
                    }
                };
            }
            public function getValues(): object
            {
                return $this->values;
            }
            public function getModified(): ?\DateTimeInterface
            {
                return $this->modified;
            }
        };
    }

    public static function writer(bool $installed = true): StoredProposalWriter
    {
        $property = $installed ? self::property(StoredProposal::TERM) : null;
        return new StoredProposalWriter(static fn () => $property, static fn () => self::newValue());
    }

    private static function proposal(string $created = '2026-11-25T10:31:07+00:00'): array
    {
        return ['created' => $created, 'model' => 'm', 'item_modified' => null,
            'alignment' => ['lrmi:teaches' => [1]], 'justifications' => []];
    }

    /** @return list<object> */
    private static function marked(object $entity): array
    {
        return array_values(array_filter(
            $entity->getValues()->items,
            static fn ($v) => StoredProposal::isProposal((string) $v->getValue())
        ));
    }

    public function testReplaceWritesOnePrivateLiteralAndKeepsEverythingElse(): void
    {
        $other = self::value(StoredProposal::TERM, 'Otro uso de curation:data');
        $title = self::value('dcterms:title', 'Un REA');
        $entity = self::entity([$other, $title, self::value(StoredProposal::TERM, StoredProposal::encode(self::proposal('2026-01-01T00:00:00+00:00')))]);

        $this->assertTrue(self::writer()->replace($entity, self::proposal()));

        $marked = self::marked($entity);
        $this->assertCount(1, $marked);
        $this->assertFalse($marked[0]->isPublic());
        $this->assertSame('literal', $marked[0]->getType());
        $this->assertSame($entity, $marked[0]->resource);
        $this->assertSame(self::proposal(), StoredProposal::decode($marked[0]->getValue()));
        $this->assertContains($other, $entity->getValues()->items);
        $this->assertContains($title, $entity->getValues()->items);
        $this->assertSame(self::proposal(), self::writer()->read($entity));
        $this->assertTrue(self::writer()->has($entity));
    }

    public function testRemoveTouchesOnlyTheMarkerValues(): void
    {
        $other = self::value(StoredProposal::TERM, 'Otro uso de curation:data');
        $entity = self::entity([$other, self::value(StoredProposal::TERM, StoredProposal::encode(self::proposal()))]);
        $this->assertTrue(self::writer()->remove($entity));
        $this->assertSame([$other], $entity->getValues()->items);
        $this->assertFalse(self::writer()->remove($entity));
        $this->assertNull(self::writer()->read($entity));
    }

    public function testACorruptProposalIsNotReadButIsRemoved(): void
    {
        $entity = self::entity([self::value(StoredProposal::TERM, StoredProposal::MARKER . ' {roto')]);
        $this->assertNull(self::writer()->read($entity));
        $this->assertTrue(self::writer()->has($entity));
        $this->assertTrue(self::writer()->remove($entity));
        $this->assertSame([], $entity->getValues()->items);
    }

    public function testTheMarkerInAnotherPropertyIsIgnored(): void
    {
        $entity = self::entity([self::value('dcterms:description', StoredProposal::encode(self::proposal()))]);
        $this->assertFalse(self::writer()->has($entity));
        $this->assertFalse(self::writer()->remove($entity));
    }

    public function testWithoutTheCurationVocabularyNothingIsWritten(): void
    {
        $entity = self::entity([]);
        $this->assertFalse(self::writer(false)->replace($entity, self::proposal()));
        $this->assertSame([], $entity->getValues()->items);
    }
}
