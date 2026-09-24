<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\PanelAreas;
use PHPUnit\Framework\TestCase;

/**
 * Áreas del panel de detalle (TASK-034, ADR-0017 §2).
 *
 * Reproduce caso por caso `test/js/detailAreas.test.js`, que se retira con el
 * módulo que probaba. Lo que se está protegiendo al portarlo no es el reparto
 * de áreas, sino la distinción que la rebanada 3a de TASK-028 pagó caro: «no se
 * pudo leer» y «no hay nada» no pueden acabar pintando lo mismo.
 */
final class PanelAreasTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private function panel(array $extra = []): array
    {
        return array_merge([
            'identity' => ['id' => 1, 'title' => 'REA', 'isPublic' => true, 'thumbnail' => null, 'editUrl' => '/edit/1'],
            'record' => [],
            'alignment' => ['groups' => [], 'axes' => [], 'orphans' => []],
            'media' => [],
        ], $extra);
    }

    /** @return array<string,mixed> */
    private function checked(): array
    {
        return ['status' => 'ok', 'issues' => []];
    }

    /** @param list<array{id:string,state:string}> $areas */
    private function area(array $areas, string $id): array
    {
        foreach ($areas as $one) {
            if ($id === $one['id']) {
                return $one;
            }
        }
        self::fail(sprintf('No hay área «%s» en el panel', $id));
    }

    public function testWithoutPanelEveryAreaIsUnknown(): void
    {
        $areas = PanelAreas::build(null, null);

        self::assertNotEmpty($areas);
        foreach ($areas as $one) {
            self::assertSame(PanelAreas::STATE_UNKNOWN, $one['state'], $one['id']);
        }
    }

    public function testAnEmptyPanelGivesEmptyAreasNotUnknownOnes(): void
    {
        $areas = PanelAreas::build($this->panel(), $this->checked());

        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($areas, 'media')['state']);
        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($areas, 'alignment')['state']);
    }

    public function testAlignmentWithGroupsIsReady(): void
    {
        $areas = PanelAreas::build(
            $this->panel(['alignment' => ['groups' => [['courseTitle' => '3º ESO']], 'axes' => [], 'orphans' => []]]),
            $this->checked()
        );

        self::assertSame(PanelAreas::STATE_READY, $this->area($areas, 'alignment')['state']);
    }

    /** Solo huérfanos NO es vacío: hay algo que enseñar, y además es lo que hay que mirar. */
    public function testAlignmentWithOnlyOrphansIsReady(): void
    {
        $areas = PanelAreas::build(
            $this->panel(['alignment' => ['groups' => [], 'axes' => [], 'orphans' => [['title' => '4º ESO']]]]),
            $this->checked()
        );

        self::assertSame(PanelAreas::STATE_READY, $this->area($areas, 'alignment')['state']);
    }

    public function testAxesAloneAlsoCountAsContent(): void
    {
        $areas = PanelAreas::build(
            $this->panel(['alignment' => ['groups' => [], 'axes' => ['Patrimonio'], 'orphans' => []]]),
            $this->checked()
        );

        self::assertSame(PanelAreas::STATE_READY, $this->area($areas, 'alignment')['state']);
    }

    public function testMediaAreaKeepsItsList(): void
    {
        $media = [['title' => 'guia.pdf', 'type' => 'application/pdf', 'size' => 10, 'url' => '/f/guia.pdf']];

        $area = $this->area(PanelAreas::build($this->panel(['media' => $media]), $this->checked()), 'media');

        self::assertSame(PanelAreas::STATE_READY, $area['state']);
        self::assertSame($media, $area['media']);
    }

    public function testRecordIsEmptyWithoutFieldsAndReadyWithThem(): void
    {
        $empty = PanelAreas::build($this->panel(), $this->checked());
        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($empty, 'record')['state']);

        $ready = PanelAreas::build($this->panel(['record' => ['dcterms:license' => 'CC BY-SA']]), $this->checked());
        self::assertSame(PanelAreas::STATE_READY, $this->area($ready, 'record')['state']);
    }

    /** El caso que da sentido al tercer estado. */
    public function testUncheckedIntegrityIsUnknownNotHealthy(): void
    {
        $areas = PanelAreas::build($this->panel(), null);

        self::assertSame(PanelAreas::STATE_UNKNOWN, $this->area($areas, 'integrity')['state']);
    }

    /** Y su reverso: comprobado y sin incidencias sigue siendo una comprobación hecha. */
    public function testCheckedIntegrityWithoutIssuesIsReady(): void
    {
        $areas = PanelAreas::build($this->panel(), $this->checked());

        self::assertSame(PanelAreas::STATE_READY, $this->area($areas, 'integrity')['state']);
    }

    public function testAreaOrderIsStableAndStartsWithAlignment(): void
    {
        $areas = PanelAreas::build($this->panel(), $this->checked());

        self::assertSame(
            ['alignment', 'media', 'record', 'governance', 'integrity'],
            array_column($areas, 'id')
        );
    }

    /** Task 9 (RF-015 read view): pins ORDER exactly, as the brief requires. */
    public function testGovernanceAreaSitsBetweenRecordAndIntegrity(): void
    {
        $this->assertSame(
            ['alignment', 'media', 'record', 'governance', 'integrity'],
            PanelAreas::ORDER
        );
        $this->assertSame('Licencia y autoría', PanelAreas::LABELS['governance']);
    }

    public function testGovernanceIsEmptyWhenAllFiveFieldsAreEmpty(): void
    {
        $areas = PanelAreas::build(
            $this->panel(),
            $this->checked(),
            ['values' => [
                'dcterms:license' => [],
                'dcterms:creator' => [],
                'dcterms:publisher' => [],
                'dcterms:rightsHolder' => [],
                'dcterms:source' => [],
            ]]
        );

        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($areas, 'governance')['state']);
    }

    public function testGovernanceIsReadyWhenAnyOfTheFiveFieldsHasAValue(): void
    {
        $areas = PanelAreas::build(
            $this->panel(),
            $this->checked(),
            ['values' => [
                'dcterms:license' => [],
                'dcterms:creator' => [['type' => 'literal', 'value' => 'Ana']],
                'dcterms:publisher' => [],
                'dcterms:rightsHolder' => [],
                'dcterms:source' => [],
            ]]
        );

        self::assertSame(PanelAreas::STATE_READY, $this->area($areas, 'governance')['state']);
    }

    /** Governance never computed (not the same as computed-and-empty): unknown, not empty. */
    public function testGovernanceWithoutDataIsUnknownNotEmpty(): void
    {
        $areas = PanelAreas::build($this->panel(), $this->checked());

        self::assertSame(PanelAreas::STATE_UNKNOWN, $this->area($areas, 'governance')['state']);
    }

    public function testEveryAreaHasATranslatableLabel(): void
    {
        foreach (PanelAreas::build($this->panel(), null) as $one) {
            self::assertArrayHasKey($one['id'], PanelAreas::LABELS);
            self::assertNotSame('', PanelAreas::LABELS[$one['id']]);
        }
    }

    public function testTheEmptyAndErrorTextsExist(): void
    {
        self::assertNotSame('', PanelAreas::PANEL_ERROR_TEXT);
        self::assertNotSame('', PanelAreas::MEDIA_EMPTY_TEXT);
        self::assertNotSame('', PanelAreas::ALIGNMENT_EMPTY_TEXT);
    }

    /**
     * Un panel al que le falten claves no debe reventar: `ItemPanelData` las
     * rellena siempre, pero esta clase es pura y la plantilla la consume
     * directamente, así que una forma inesperada tiene que degradar a «vacío»
     * en vez de a error 500 con el sidebar abierto.
     */
    public function testAPanelMissingKeysDegradesToEmptyInsteadOfFailing(): void
    {
        $areas = PanelAreas::build(['identity' => []], $this->checked());

        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($areas, 'alignment')['state']);
        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($areas, 'media')['state']);
        self::assertSame(PanelAreas::STATE_EMPTY, $this->area($areas, 'record')['state']);
    }
}
