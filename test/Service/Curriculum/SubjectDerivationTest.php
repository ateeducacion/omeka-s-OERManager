<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\SubjectDerivation;
use PHPUnit\Framework\TestCase;

final class SubjectDerivationTest extends TestCase
{
    private static function about(?string $class, array $subjects): array
    {
        return ['class' => $class, 'subjects' => $subjects];
    }

    private static function subject(int $id, string $title, ?string $class = 'cur:Materia'): array
    {
        return ['id' => $id, 'class' => $class, 'title' => $title];
    }

    public function testTwoYearsOfOneSubjectGiveOneValue(): void
    {
        $maths = self::subject(10, 'Matemáticas (ESO)');
        $derived = SubjectDerivation::derive([
            self::about('cur:MateriaCurso', [$maths]),
            self::about('cur:MateriaCurso', [$maths]),
        ]);
        $this->assertSame([['id' => 10, 'title' => 'Matemáticas (ESO)']], $derived);
    }

    public function testSubjectsAreOrderedByTitleThenId(): void
    {
        $derived = SubjectDerivation::derive([
            self::about('cur:MateriaCurso', [self::subject(30, 'Matemáticas (ESO)')]),
            self::about('cur:MateriaCurso', [self::subject(20, 'Biología (Bachillerato)')]),
            self::about('cur:MateriaCurso', [self::subject(5, 'Matemáticas (ESO)')]),
        ]);
        $this->assertSame([20, 5, 30], SubjectDerivation::ids($derived));
    }

    public function testOnlyMateriaTargetsOfMateriaCursoCount(): void
    {
        $this->assertSame([], SubjectDerivation::derive([
            // A literal or URI schema:about value: no target class.
            self::about(null, []),
            // Another class of target.
            self::about('cur:Curso', [self::subject(1, 'X')]),
            // A MateriaCurso without cur:materia.
            self::about('cur:MateriaCurso', []),
            // cur:materia to something that is not a Materia.
            self::about('cur:MateriaCurso', [self::subject(2, 'Y', 'cur:Etapa')]),
            self::about('cur:MateriaCurso', [self::subject(3, 'Z', null)]),
        ]));
        $this->assertSame([], SubjectDerivation::derive([]));
    }

    public function testMatchesNeedsTheSameIdsInTheSameOrder(): void
    {
        $this->assertTrue(SubjectDerivation::matches([], []));
        $this->assertTrue(SubjectDerivation::matches([20, 5], [20, 5]));
        $this->assertFalse(SubjectDerivation::matches([5, 20], [20, 5]));
        $this->assertFalse(SubjectDerivation::matches([20], [20, 5]));
        $this->assertFalse(SubjectDerivation::matches([20, 5, 7], [20, 5]));
        // A stored value that is not an item link never matches.
        $this->assertFalse(SubjectDerivation::matches([20, null], [20]));
    }
}
