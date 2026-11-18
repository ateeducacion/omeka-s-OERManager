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
        $this->assertSame('Descartado', WorkflowStatus::DISCARDED);
        $this->assertSame('Publicado', WorkflowStatus::PUBLISHED);
    }

    public function testNormalizeTrataVacioComoBorrador(): void
    {
        $this->assertNull(WorkflowStatus::normalize(null));
        $this->assertNull(WorkflowStatus::normalize(''));
        $this->assertNull(WorkflowStatus::normalize("  \t"));
        $this->assertSame('Propuesto', WorkflowStatus::normalize(' Propuesto '));
    }

    /** RF-017 + TASK-067: one badge per known state, exact match, none for Borrador. */
    public function testBadgePorEstadoConocido(): void
    {
        $this->assertSame('proposed', WorkflowStatus::badge(WorkflowStatus::PROPOSED));
        $this->assertSame('rejected', WorkflowStatus::badge(WorkflowStatus::REJECTED));
        $this->assertSame('discarded', WorkflowStatus::badge(WorkflowStatus::DISCARDED));
        $this->assertSame('published', WorkflowStatus::badge(WorkflowStatus::PUBLISHED));
        $this->assertNull(WorkflowStatus::badge(null));
        $this->assertNull(WorkflowStatus::badge('propuesto'));
    }

    public function testProponerDesdeBorradorOAusente(): void
    {
        $this->assertTrue(WorkflowStatus::canPropose(null, false));
    }

    public function testProponerDesdeRechazado(): void
    {
        $this->assertTrue(WorkflowStatus::canPropose(WorkflowStatus::REJECTED, false));
    }

    public function testNoSePuedeProponerDosVeces(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose(WorkflowStatus::PROPOSED, false));
    }

    public function testNoSePuedeProponerUnValorDesconocido(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose('cualquier-otra-cosa', false));
    }

    /**
     * Owner's review (2026-11-18): proposing a public REA left it Propuesto
     * and still public, in the queue and on the site at once. A public REA
     * goes to the queue with «Despublicar», which makes it private.
     */
    public function testNoSePuedeProponerUnReaPublico(): void
    {
        $this->assertFalse(WorkflowStatus::canPropose(null, true));
        $this->assertFalse(WorkflowStatus::canPropose(WorkflowStatus::REJECTED, true));
    }

    public function testLosBotonesDeUnReaPublicoSinEstadoNoIncluyenProponer(): void
    {
        $buttons = WorkflowStatus::available(null, true, static fn (string $privilege): bool => true);
        $this->assertFalse($buttons['canPropose']);
        $this->assertTrue($buttons['canPublish']);
        $this->assertTrue($buttons['canUnpublish']);
        $this->assertTrue($buttons['canDiscard']);
    }

    /** The item page names the state, out of flow included (redesign of 2026-11-18). */
    public function testInsigniaDeLaPaginaDelItem(): void
    {
        $this->assertSame('out_of_flow', WorkflowStatus::pageBadge(null, true));
        $this->assertSame('out_of_flow', WorkflowStatus::pageBadge(WorkflowStatus::PROPOSED, true));
        $this->assertSame('out_of_flow', WorkflowStatus::pageBadge(WorkflowStatus::REJECTED, true));
        $this->assertSame('published', WorkflowStatus::pageBadge(WorkflowStatus::PUBLISHED, true));
        $this->assertSame('proposed', WorkflowStatus::pageBadge(WorkflowStatus::PROPOSED, false));
        $this->assertSame('discarded', WorkflowStatus::pageBadge(WorkflowStatus::DISCARDED, false));
        $this->assertNull(WorkflowStatus::pageBadge(null, false));
    }

    public function testSoloSePuedeRechazarUnPropuesto(): void
    {
        $this->assertTrue(WorkflowStatus::canReject(WorkflowStatus::PROPOSED));
        $this->assertFalse(WorkflowStatus::canReject(null));
        $this->assertFalse(WorkflowStatus::canReject(WorkflowStatus::REJECTED));
    }

    /**
     * TASK-067: Propuesto publishes; a public REA outside the flow can be
     * regularised by confirming it, unless it is already Publicado or Descartado.
     */
    public function testPublicarUnPropuestoOUnPublicoFueraDelFlujo(): void
    {
        $this->assertTrue(WorkflowStatus::canPublish(WorkflowStatus::PROPOSED, false));
        $this->assertTrue(WorkflowStatus::canPublish(WorkflowStatus::PROPOSED, true));
        $this->assertFalse(WorkflowStatus::canPublish(null, false));
        $this->assertFalse(WorkflowStatus::canPublish(WorkflowStatus::REJECTED, false));
        $this->assertTrue(WorkflowStatus::canPublish(null, true));
        $this->assertTrue(WorkflowStatus::canPublish(WorkflowStatus::REJECTED, true));
        $this->assertFalse(WorkflowStatus::canPublish(WorkflowStatus::PUBLISHED, true));
        $this->assertFalse(WorkflowStatus::canPublish(WorkflowStatus::DISCARDED, true));
        $this->assertFalse(WorkflowStatus::canPublish(WorkflowStatus::DISCARDED, false));
    }

    public function testDescartarDesdeBorradorPropuestoORechazado(): void
    {
        foreach ([null, WorkflowStatus::PROPOSED, WorkflowStatus::REJECTED] as $status) {
            $this->assertTrue(WorkflowStatus::canDiscard($status));
        }
        $this->assertFalse(WorkflowStatus::canDiscard(WorkflowStatus::PUBLISHED));
        $this->assertFalse(WorkflowStatus::canDiscard(WorkflowStatus::DISCARDED));
        $this->assertFalse(WorkflowStatus::canDiscard('otro'));
    }

    public function testReabrirSoloUnDescartado(): void
    {
        $this->assertTrue(WorkflowStatus::canReopen(WorkflowStatus::DISCARDED));
        foreach ([null, WorkflowStatus::PROPOSED, WorkflowStatus::REJECTED, WorkflowStatus::PUBLISHED] as $status) {
            $this->assertFalse(WorkflowStatus::canReopen($status));
        }
    }

    public function testDespublicarUnPublicadoOCualquierPublico(): void
    {
        $this->assertTrue(WorkflowStatus::canUnpublish(WorkflowStatus::PUBLISHED, true));
        $this->assertTrue(WorkflowStatus::canUnpublish(WorkflowStatus::PUBLISHED, false));
        $this->assertTrue(WorkflowStatus::canUnpublish(null, true));
        $this->assertFalse(WorkflowStatus::canUnpublish(null, false));
        $this->assertFalse(WorkflowStatus::canUnpublish(WorkflowStatus::PROPOSED, false));
    }

    /** ADR-0023 §2: a public REA without Publicado is out of the flow; the queue skips Descartado. */
    public function testFueraDelFlujoYCola(): void
    {
        $this->assertTrue(WorkflowStatus::isOutOfFlow(null, true));
        $this->assertTrue(WorkflowStatus::isOutOfFlow(WorkflowStatus::DISCARDED, true));
        $this->assertFalse(WorkflowStatus::isOutOfFlow(WorkflowStatus::PUBLISHED, true));
        $this->assertFalse(WorkflowStatus::isOutOfFlow(null, false));
        $this->assertTrue(WorkflowStatus::inQueue(WorkflowStatus::PROPOSED, false));
        $this->assertTrue(WorkflowStatus::inQueue(null, true));
        $this->assertFalse(WorkflowStatus::inQueue(WorkflowStatus::DISCARDED, true));
        $this->assertFalse(WorkflowStatus::inQueue(WorkflowStatus::REJECTED, false));
        $this->assertFalse(WorkflowStatus::inQueue(WorkflowStatus::PUBLISHED, true));
    }
}
