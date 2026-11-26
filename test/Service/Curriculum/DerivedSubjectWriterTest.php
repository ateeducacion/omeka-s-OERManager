<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\DerivedSubjectWriter;
use PHPUnit\Framework\TestCase;

final class DerivedSubjectWriterTest extends TestCase
{
    /** @var list<object> values the writer created */
    private array $created = [];
    private object $alignment;

    protected function setUp(): void
    {
        $this->alignment = $this->member('lrmi:educationalAlignment');
    }

    private function writer(bool $installed = true): DerivedSubjectWriter
    {
        return new DerivedSubjectWriter(
            fn (): ?object => $installed ? $this->alignment : null,
            function (): object {
                $value = new class {
                    public $resource;
                    public $property;
                    public $type;
                    public $valueResource;
                    public $isPublic;
                    public function setResource($r)
                    {
                        $this->resource = $r;
                    }
                    public function setProperty($p)
                    {
                        $this->property = $p;
                    }
                    public function setType($t)
                    {
                        $this->type = $t;
                    }
                    public function setValueResource($r)
                    {
                        $this->valueResource = $r;
                    }
                    public function setIsPublic($p)
                    {
                        $this->isPublic = $p;
                    }
                    public function getProperty()
                    {
                        return $this->property;
                    }
                    public function getValueResource()
                    {
                        return $this->valueResource;
                    }
                };
                $this->created[] = $value;
                return $value;
            }
        );
    }

    /** A property or resource class entity: `getVocabulary()->getPrefix()` and `getLocalName()`. */
    private function member(string $term): object
    {
        [$prefix, $local] = explode(':', $term);
        $vocabulary = new class ($prefix) {
            public function __construct(private string $p)
            {
            }
            public function getPrefix()
            {
                return $this->p;
            }
        };
        return new class ($vocabulary, $local) {
            public function __construct(private object $v, private string $l)
            {
            }
            public function getVocabulary()
            {
                return $this->v;
            }
            public function getLocalName()
            {
                return $this->l;
            }
        };
    }

    private function collection(array $values): object
    {
        return new class ($values) implements \IteratorAggregate {
            public function __construct(public array $items)
            {
            }
            public function getIterator(): \Iterator
            {
                return new \ArrayIterator($this->items);
            }
            public function add($v)
            {
                $this->items[] = $v;
            }
            public function removeElement($v)
            {
                $this->items = array_values(array_filter($this->items, static fn ($x) => $x !== $v));
            }
        };
    }

    private function resource(int $id, ?string $class, string $title = '', array $values = [], bool $public = true): object
    {
        $classEntity = null === $class ? null : $this->member($class);
        return new class ($id, $classEntity, $title, $this->collection($values), $public) {
            public function __construct(
                private int $id,
                private $class,
                private string $title,
                private object $values,
                private bool $public
            ) {
            }
            public function isPublic()
            {
                return $this->public;
            }
            public function getId()
            {
                return $this->id;
            }
            public function getResourceClass()
            {
                return $this->class;
            }
            public function getTitle()
            {
                return $this->title;
            }
            public function getValues()
            {
                return $this->values;
            }
        };
    }

    private function value(string $term, ?object $target, bool $public = true): object
    {
        return new class ($this->member($term), $target, $public) {
            public function __construct(private object $p, private ?object $t, private bool $public)
            {
            }
            public function isPublic()
            {
                return $this->public;
            }
            public function getProperty()
            {
                return $this->p;
            }
            public function getValueResource()
            {
                return $this->t;
            }
        };
    }

    /** A MateriaCurso item whose cur:materia points to $subject. */
    private function materiaCurso(int $id, ?object $subject): object
    {
        $values = null === $subject ? [] : [$this->value('cur:materia', $subject)];
        return $this->resource($id, 'cur:MateriaCurso', 'MC ' . $id, $values);
    }

    private function rea(array $values, string $class = 'lrmi:LearningResource'): object
    {
        return $this->resource(1, $class, 'REA', $values);
    }

    /** @return list<?int> target ids of the REA's lrmi:educationalAlignment values, in order */
    private function alignmentIds(object $rea): array
    {
        $ids = [];
        foreach ($rea->getValues() as $value) {
            if ('educationalAlignment' === $value->getProperty()->getLocalName()) {
                $ids[] = $value->getValueResource()?->getId();
            }
        }
        return $ids;
    }

    public function testApplyWritesOneValuePerStageSubjectInTitleOrder(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $biology = $this->resource(20, 'cur:Materia', 'Biología y Geología (ESO)');
        $rea = $this->rea([
            $this->value('schema:about', $this->materiaCurso(101, $maths)),
            $this->value('schema:about', $this->materiaCurso(102, $maths)),
            $this->value('schema:about', $this->materiaCurso(103, $biology)),
        ]);
        $this->assertTrue($this->writer()->apply($rea));
        $this->assertSame([20, 30], $this->alignmentIds($rea));
        $this->assertCount(2, $this->created);
        foreach ($this->created as $value) {
            $this->assertSame($rea, $value->resource);
            $this->assertSame($this->alignment, $value->property);
            $this->assertSame('resource:item', $value->type);
            $this->assertTrue($value->isPublic);
        }
    }

    public function testApplyReplacesHandWrittenAndStaleValues(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $rea = $this->rea([
            $this->value('schema:about', $this->materiaCurso(101, $maths)),
            $this->value('lrmi:educationalAlignment', $this->resource(99, 'cur:Materia', 'Old')),
            $this->value('lrmi:educationalAlignment', null),
        ]);
        $this->assertSame([99, null], $this->writer()->storedIds($rea));
        $this->assertTrue($this->writer()->apply($rea));
        $this->assertSame([30], $this->alignmentIds($rea));
    }

    public function testRemovingEveryAboutRemovesTheDerivedValues(): void
    {
        $rea = $this->rea([$this->value('lrmi:educationalAlignment', $this->resource(30, 'cur:Materia', 'M'))]);
        $this->assertTrue($this->writer()->apply($rea));
        $this->assertSame([], $this->alignmentIds($rea));
        $this->assertSame([], $this->created);
    }

    public function testApplyLeavesMatchingValuesAlone(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $stored = $this->value('lrmi:educationalAlignment', $maths);
        $rea = $this->rea([$this->value('schema:about', $this->materiaCurso(101, $maths)), $stored]);
        $this->assertFalse($this->writer()->apply($rea));
        $this->assertSame([], $this->created);
        $this->assertContains($stored, iterator_to_array($rea->getValues()));
    }

    public function testTargetsWithoutClassOrResourceContributeNothing(): void
    {
        $rea = $this->rea([
            $this->value('schema:about', null),
            $this->value('schema:about', $this->resource(5, null)),
            $this->value('schema:about', $this->materiaCurso(6, null)),
            $this->value('schema:about', $this->resource(7, 'cur:Curso', '1º ESO', [
                $this->value('cur:materia', $this->resource(30, 'cur:Materia', 'M')),
            ])),
        ]);
        $this->assertFalse($this->writer()->apply($rea));
        $this->assertSame([], $this->writer()->derivedIds($rea));
    }

    public function testOnlyReaAreTouched(): void
    {
        $alignment = $this->value('lrmi:educationalAlignment', $this->resource(30, 'cur:Materia', 'M'));
        $other = $this->rea([$alignment], 'cur:Curso');
        $this->assertFalse($this->writer()->isRea($other));
        $this->assertFalse($this->writer()->apply($other));
        $this->assertSame([30], $this->alignmentIds($other));
        $this->assertFalse($this->writer()->apply($this->resource(2, null)));
    }

    public function testWithoutTheLrmiPropertyNothingIsWritten(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'M');
        $rea = $this->rea([$this->value('schema:about', $this->materiaCurso(101, $maths))]);
        $this->assertFalse($this->writer(false)->apply($rea));
        $this->assertSame([], $this->alignmentIds($rea));
    }

    /**
     * Security review of TASK-076: the derived value is public, so it may only
     * repeat what is already public. A private cur:materia edge or a private
     * cur:Materia item contributes nothing.
     */
    public function testPrivateEdgesAndSubjectsAreNotDerived(): void
    {
        $privateSubject = $this->resource(40, 'cur:Materia', 'Privada', [], false);
        $public = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $privateEdge = $this->resource(101, 'cur:MateriaCurso', 'MC', [$this->value('cur:materia', $public, false)]);
        $rea = $this->rea([
            $this->value('schema:about', $privateEdge),
            $this->value('schema:about', $this->materiaCurso(102, $privateSubject)),
        ]);
        $this->assertSame([], $this->writer()->derivedIds($rea));
        $this->assertFalse($this->writer()->apply($rea));
    }

    /**
     * Security review of TASK-076 (second pass): the whole chain must be
     * public. A private schema:about value of the REA, or a private
     * MateriaCurso item, would otherwise leak through the public derived value.
     */
    public function testAPrivateAboutValueOrMateriaCursoIsNotDerived(): void
    {
        $maths = $this->resource(30, 'cur:Materia', 'Matemáticas (ESO)');
        $privateMateriaCurso = $this->resource(102, 'cur:MateriaCurso', 'MC', [$this->value('cur:materia', $maths)], false);
        $rea = $this->rea([
            $this->value('schema:about', $this->materiaCurso(101, $maths), false),
            $this->value('schema:about', $privateMateriaCurso),
        ]);
        $this->assertSame([], $this->writer()->derivedIds($rea));
        $this->assertFalse($this->writer()->apply($rea));
    }
}
