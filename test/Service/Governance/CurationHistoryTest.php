<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurationHistory;
use PHPUnit\Framework\TestCase;

/**
 * Modelo de presentación del historial de curación (ADR-0015, rebanada 3a).
 *
 * El historial sale de los EVENTOS, no de las value annotations: una anotación
 * vive en el valor, así que al vaciar una dimensión desaparece con ella. El
 * evento sí registra el vaciado, y ese es el caso que más importa probar.
 */
final class CurationHistoryTest extends TestCase
{
    private function event(array $terms, string $when = '2026-08-11T10:00:00+00:00', string $op = 'recatalog'): array
    {
        return [
            'when' => $when,
            'contributor' => 'fmatdia',
            'summary' => 'Re-catalogación · lrmi:teaches +1',
            'payload' => ['v' => 1, 'op' => $op, 'undoOf' => null, 'terms' => $terms],
        ];
    }

    public function testEmptyHistoryIsEmptyNotAnError(): void
    {
        $this->assertSame([], CurationHistory::rows([], []));
    }

    public function testCarriesTheReadableSummaryAndWho(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]])],
            [7 => 'Números enteros (1º ESO)']
        );

        $this->assertCount(1, $rows);
        $this->assertSame('fmatdia', $rows[0]['contributor']);
        $this->assertSame('Re-catalogación · lrmi:teaches +1', $rows[0]['summary']);
        $this->assertSame('2026-08-11T10:00:00+00:00', $rows[0]['when']);
        $this->assertFalse($rows[0]['isUndo']);
    }

    public function testAddedValuesAreResolvedToTitles(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7, 8]]])],
            [7 => 'Números enteros (1º ESO)', 8 => 'Fracciones (2º ESO)']
        );

        $this->assertSame(['lrmi:teaches'], array_column($rows[0]['changes'], 'term'));
        $this->assertSame(
            ['Números enteros (1º ESO)', 'Fracciones (2º ESO)'],
            $rows[0]['changes'][0]['added']
        );
        $this->assertSame([], $rows[0]['changes'][0]['removed']);
    }

    /** El caso que las anotaciones NO pueden representar: la dimensión se vacía. */
    public function testAnEmptiedDimensionIsRecordedWithItsRemovedValues(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [7, 8], 'after' => []]])],
            [7 => 'Números enteros (1º ESO)', 8 => 'Fracciones (2º ESO)']
        );

        $change = $rows[0]['changes'][0];
        $this->assertTrue($change['emptied']);
        $this->assertSame([], $change['added']);
        $this->assertSame(
            ['Números enteros (1º ESO)', 'Fracciones (2º ESO)'],
            array_column($change['removed'], 'title')
        );
    }

    public function testAValueThatStayedIsNeitherAddedNorRemoved(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [7, 8], 'after' => [7, 9]]])],
            [7 => 'Se queda', 8 => 'Se va', 9 => 'Llega']
        );

        $change = $rows[0]['changes'][0];
        $this->assertSame(['Llega'], $change['added']);
        $this->assertSame(['Se va'], array_column($change['removed'], 'title'));
        $this->assertFalse($change['emptied']);
    }

    /** La justificación de la IA acompaña a los valores RETIRADOS (ver nota del plan). */
    public function testTheReasonTravelsWithTheRemovedValue(): void
    {
        $rows = CurationHistory::rows(
            [$this->event([
                'lrmi:teaches' => [
                    'before' => [7, 8],
                    'after' => [],
                    'why' => [7 => 'El recurso trabaja la recta numérica'],
                ],
            ])],
            [7 => 'Números enteros', 8 => 'Fracciones']
        );

        $removed = $rows[0]['changes'][0]['removed'];
        $this->assertSame('Números enteros', $removed[0]['title']);
        $this->assertSame('El recurso trabaja la recta numérica', $removed[0]['reason']);
        $this->assertSame('', $removed[1]['reason'], 'sin porqué se rinde cadena vacía, no null');
    }

    /**
     * Hallazgo 2 de la revisión final de rama (rebanada 3a): `when` crudo lleva
     * microsegundos y offset porque el desempate del deshacer los necesita
     * (TASK-007), no porque el curador deba leerlos. `whenLabel` es la versión
     * para pintar; `when` se conserva intacto en la fila.
     */
    public function testWhenLabelIsHumanReadable(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]], '2026-08-11T10:00:00.123456+00:00')],
            [7 => 'Números enteros']
        );

        $this->assertSame('2026-08-11T10:00:00.123456+00:00', $rows[0]['when'], 'el crudo no se toca');
        $this->assertSame('11/08/2026 10:00', $rows[0]['whenLabel']);
    }

    /** Un `when` que no se pueda parsear no revienta el historial: cae al crudo. */
    public function testWhenLabelFallsBackToTheRawValueWhenUnparseable(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]], 'no-es-una-fecha')],
            [7 => 'Números enteros']
        );

        $this->assertSame('no-es-una-fecha', $rows[0]['whenLabel']);
    }

    public function testAnUndoIsMarkedAsSuch(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]], '2026-08-11T10:00:00+00:00', 'undo')],
            [7 => 'Números enteros']
        );

        $this->assertTrue($rows[0]['isUndo']);
    }

    /** Un destino borrado del currículo no puede reventar el historial. */
    public function testAnUnresolvedIdFallsBackToItsNumber(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['schema:about' => ['before' => [], 'after' => [404]]])],
            []
        );

        $this->assertSame(['#404'], $rows[0]['changes'][0]['added']);
    }

    /** Una dimensión sin cambio real no debería estar en el payload, pero si llega, se omite. */
    public function testATermWithNoChangeIsOmitted(): void
    {
        $rows = CurationHistory::rows(
            [$this->event([
                'lrmi:teaches' => ['before' => [7], 'after' => [7]],
                'schema:about' => ['before' => [], 'after' => [9]],
            ])],
            [7 => 'Igual', 9 => 'Nuevo']
        );

        $this->assertSame(['schema:about'], array_column($rows[0]['changes'], 'term'));
    }

    public function testTheOrderGivenIsPreserved(): void
    {
        $rows = CurationHistory::rows([
            $this->event(['lrmi:teaches' => ['before' => [], 'after' => [7]]], '2026-08-11T12:00:00+00:00'),
            $this->event(['lrmi:teaches' => ['before' => [], 'after' => [8]]], '2026-08-11T09:00:00+00:00'),
        ], [7 => 'A', 8 => 'B']);

        $this->assertSame(
            ['2026-08-11T12:00:00+00:00', '2026-08-11T09:00:00+00:00'],
            array_column($rows, 'when')
        );
    }

    /** Ids que hay que resolver: los de todos los eventos, sin repetir. */
    public function testReferencedIdsCollectsBeforeAndAfterWithoutDuplicates(): void
    {
        $ids = CurationHistory::referencedIds([
            $this->event(['lrmi:teaches' => ['before' => [7, 8], 'after' => [8, 9]]]),
            $this->event(['schema:about' => ['before' => [], 'after' => [7]]]),
        ]);

        sort($ids);
        $this->assertSame([7, 8, 9], $ids);
    }

    public function testReferencedIdsOfNothingIsEmpty(): void
    {
        $this->assertSame([], CurationHistory::referencedIds([]));
    }
}
