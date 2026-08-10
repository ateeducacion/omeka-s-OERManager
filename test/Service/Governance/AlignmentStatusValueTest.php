<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\AlignmentStatusValue;
use PHPUnit\Framework\TestCase;

/**
 * Regla de estado de anclaje curricular (ADR-0005 §4, ampliada 2026-08-10).
 *
 * La ampliación: un curso del REA al que ninguna de sus materias corresponde es
 * un **anclaje mal hecho**, no un caso de visualización, así que degrada el
 * estado a `partial`. Decidido por el propietario al ver la tabla renderizada:
 * 7 de los 19 REA del catálogo real están en esa situación y hasta ahora se
 * contaban como `complete`.
 *
 * Efecto colateral valioso: el filtro «parcial» pasa a tener datos con los que
 * ejercitarse, cosa que el proyecto arrastraba pendiente desde TASK-028.
 */
final class AlignmentStatusValueTest extends TestCase
{
    public function testTheThreeLiteralsAreStable(): void
    {
        $this->assertSame('complete', AlignmentStatusValue::COMPLETE);
        $this->assertSame('partial', AlignmentStatusValue::PARTIAL);
        $this->assertSame('none', AlignmentStatusValue::NONE);
    }

    /** Sin criterios ni saberes no hay anclaje que graduar. */
    public function testNoCriteriaAndNoSkillsIsNone(): void
    {
        $this->assertSame(
            AlignmentStatusValue::NONE,
            AlignmentStatusValue::fromFlags(true, true, false, false, false)
        );
    }

    /** Un curso huérfano no rescata a un REA sin criterios ni saberes. */
    public function testNoneWinsOverAnOrphanCourse(): void
    {
        $this->assertSame(
            AlignmentStatusValue::NONE,
            AlignmentStatusValue::fromFlags(true, true, false, false, true)
        );
    }

    public function testAllFourDimensionsAndNoOrphanIsComplete(): void
    {
        $this->assertSame(
            AlignmentStatusValue::COMPLETE,
            AlignmentStatusValue::fromFlags(true, true, true, true, false)
        );
    }

    /** La ampliación: el curso sin materia degrada un anclaje por lo demás completo. */
    public function testAnOrphanCourseDegradesACompleteAnchorToPartial(): void
    {
        $this->assertSame(
            AlignmentStatusValue::PARTIAL,
            AlignmentStatusValue::fromFlags(true, true, true, true, true)
        );
    }

    public function testAMissingDimensionIsStillPartial(): void
    {
        $this->assertSame(
            AlignmentStatusValue::PARTIAL,
            AlignmentStatusValue::fromFlags(false, true, true, true, false)
        );
        $this->assertSame(
            AlignmentStatusValue::PARTIAL,
            AlignmentStatusValue::fromFlags(true, false, true, true, false)
        );
    }

    public function testOnlySkillsIsPartial(): void
    {
        $this->assertSame(
            AlignmentStatusValue::PARTIAL,
            AlignmentStatusValue::fromFlags(false, false, false, true, false)
        );
    }
}
