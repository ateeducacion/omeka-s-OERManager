<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * Máquina de estados del flujo autor→curador (RF-016, ADR-0018). Puro: solo
 * compara el literal de `curation:status`, nunca toca el core.
 */
final class WorkflowStatusTest extends TestCase
{
    public function testConstantesDeTermino(): void
    {
        $this->assertSame('curation:status', WorkflowStatus::STATUS_TERM);
        $this->assertSame('curation:note', WorkflowStatus::NOTE_TERM);
        $this->assertSame('Propuesto', WorkflowStatus::PROPOSED);
        $this->assertSame('Rechazado', WorkflowStatus::REJECTED);
    }

    public function testNormalizeTrataVacioComoBorrador(): void
    {
        $this->assertNull(WorkflowStatus::normalize(null));
        $this->assertNull(WorkflowStatus::normalize(''));
        $this->assertNull(WorkflowStatus::normalize("  \t"));
        $this->assertSame('Propuesto', WorkflowStatus::normalize(' Propuesto '));
    }

    /** RF-017: insignia exactamente para Propuesto y Rechazado. */
    public function testBadgeSoloParaPropuestoYRechazado(): void
    {
        $this->assertSame('proposed', WorkflowStatus::badge(WorkflowStatus::PROPOSED));
        $this->assertSame('rejected', WorkflowStatus::badge(WorkflowStatus::REJECTED));
        $this->assertNull(WorkflowStatus::badge(null));
        $this->assertNull(WorkflowStatus::badge('propuesto'));
        $this->assertNull(WorkflowStatus::badge('Publicado'));
    }

    public function testProponerDesdeBorradorOAusente(): void
    {
        $this->assertTrue(WorkflowStatus::canPropose(null));
    }

    public function testProponerDesdeRechazado(): void
    {
        $this->assertTrue(WorkflowStatus::canPropose(WorkflowStatus::REJECTED));
    }

    public function testNoSePuedeProponerDosVeces(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose(WorkflowStatus::PROPOSED));
    }

    public function testNoSePuedeProponerUnValorDesconocido(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose('cualquier-otra-cosa'));
    }

    public function testSoloSePuedeRechazarUnPropuesto(): void
    {
        $this->assertTrue(WorkflowStatus::canReject(WorkflowStatus::PROPOSED));
        $this->assertFalse(WorkflowStatus::canReject(null));
        $this->assertFalse(WorkflowStatus::canReject(WorkflowStatus::REJECTED));
    }

    public function testSoloSePuedePublicarUnPropuesto(): void
    {
        $this->assertTrue(WorkflowStatus::canPublish(WorkflowStatus::PROPOSED));
        $this->assertFalse(WorkflowStatus::canPublish(null));
        $this->assertFalse(WorkflowStatus::canPublish(WorkflowStatus::REJECTED));
    }
}
