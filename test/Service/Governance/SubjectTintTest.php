<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\SubjectTint;
use PHPUnit\Framework\TestCase;

/**
 * TASK-046 / ADR-0014 addendum 2026-10-03: el tinte es una ayuda de agrupación,
 * no un estado. Tiene que ser estable por materia, y la misma materia tiene que
 * llevar el mismo tinte en todas las filas aunque sea un item-término distinto
 * en cada curso («Educación física» existe como 11 items en el catálogo real).
 */
final class SubjectTintTest extends TestCase
{
    public function testTheIndexIsInsideThePalette(): void
    {
        foreach (['Matemáticas', 'Lengua', 'x', 'Ñandú', ''] as $subject) {
            $index = SubjectTint::indexFor($subject);
            $this->assertGreaterThanOrEqual(1, $index);
            $this->assertLessThanOrEqual(SubjectTint::PALETTE_SIZE, $index);
        }
    }

    public function testTheSameSubjectAlwaysGetsTheSameTint(): void
    {
        $this->assertSame(SubjectTint::indexFor('Matemáticas'), SubjectTint::indexFor('Matemáticas'));
    }

    public function testCaseDiacriticsAndWhitespaceDoNotChangeTheTint(): void
    {
        $expected = SubjectTint::indexFor('Educación física');

        $this->assertSame($expected, SubjectTint::indexFor('  EDUCACION   FISICA '));
        $this->assertSame($expected, SubjectTint::indexFor("educación\u{00A0}física"));
        // Mismo texto con la «ó» descompuesta (o + acento combinante).
        $this->assertSame($expected, SubjectTint::indexFor("educacio\u{0301}n fi\u{0301}sica"));
    }

    public function testRealSubjectsSpreadAcrossThePalette(): void
    {
        $subjects = [
            'Matemáticas', 'Lengua Castellana y Literatura', 'Biología y Geología', 'Física y Química',
            'Educación física', 'Filosofía', 'Geografía e Historia', 'Inglés', 'Música', 'Tecnología',
            'Dibujo', 'Religión',
        ];
        $used = array_unique(array_map([SubjectTint::class, 'indexFor'], $subjects));

        $this->assertGreaterThanOrEqual(5, count($used), 'una paleta de 8 no puede colapsar en 1-2 tintes');
    }
}
