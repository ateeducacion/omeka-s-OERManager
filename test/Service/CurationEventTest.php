<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\CurationEvent;
use PHPUnit\Framework\TestCase;

/**
 * Formato del evento de curación (TASK-007, ADR-0015). Es la única pieza de la
 * reversibilidad que se puede probar en el host: RecatalogService depende del
 * core de Omeka y no es instanciable aquí, así que el formato del payload —de
 * cuya exactitud depende que un deshacer restaure el REA— se aísla en esta
 * clase pura para poder ejercitarlo de verdad.
 */
final class CurationEventTest extends TestCase
{
    public function testRecordsWhatEachDimensionHadBeforeAndAfter(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101, 102], 'after' => [101, 305]],
        ]);

        $this->assertSame([101, 102], $payload['terms']['lrmi:teaches']['before']);
        $this->assertSame([101, 305], $payload['terms']['lrmi:teaches']['after']);
    }

    public function testDimensionsThatDidNotChangeAreLeftOutOfThePayload(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101], 'after' => [101, 305]],
            'schema:about' => ['before' => [7], 'after' => [7]],
        ]);

        $this->assertArrayHasKey('lrmi:teaches', $payload['terms']);
        $this->assertArrayNotHasKey('schema:about', $payload['terms']);
    }

    public function testAnEmptiedDimensionIsARecordedChange(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101, 102], 'after' => []],
        ]);

        $this->assertSame([101, 102], $payload['terms']['lrmi:teaches']['before']);
        $this->assertSame([], $payload['terms']['lrmi:teaches']['after']);
    }

    public function testAnApplyThatChangesNothingIsNotAnEvent(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101], 'after' => [101]],
        ]);

        $this->assertNull($payload);
    }

    public function testReorderingTheSameIdsIsNotAChange(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [102, 101], 'after' => [101, 102]],
        ]);

        $this->assertNull($payload);
    }

    public function testKeepsTheReasonOfEveryPreviousValueNotJustTheRemovedOnes(): void
    {
        // El deshacer reescribe la property entera desde 'before', así que cada
        // valor restaurado necesita su porqué de vuelta, se fuera o se quedara.
        $payload = CurationEvent::build([
            'lrmi:teaches' => [
                'before' => [101, 102],
                'after' => [305],
                'why' => [101 => 'porque sí', 102 => 'porque también'],
            ],
        ]);

        $this->assertSame(
            ['101' => 'porque sí', '102' => 'porque también'],
            $payload['terms']['lrmi:teaches']['why']
        );
    }

    public function testDropsReasonsOfValuesThatWereNotThereBefore(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101], 'after' => [305], 'why' => [101 => 'a', 305 => 'b']],
        ]);

        $this->assertSame(['101' => 'a'], $payload['terms']['lrmi:teaches']['why']);
    }

    public function testMarksARevertWithTheEventItReverts(): void
    {
        $payload = CurationEvent::build(
            ['lrmi:teaches' => ['before' => [305], 'after' => [101]]],
            '2026-07-30T10:31:00+00:00'
        );

        $this->assertSame(CurationEvent::OP_UNDO, $payload['op']);
        $this->assertSame('2026-07-30T10:31:00+00:00', $payload['undoOf']);
    }

    public function testTheSummaryTellsAHumanWhatChangedWithoutParsingJson(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101, 102], 'after' => [101, 305]],
            'schema:about' => ['before' => [], 'after' => [7]],
        ]);

        $this->assertSame(
            'Re-catalogación · lrmi:teaches +1 −1 · schema:about +1',
            CurationEvent::summary($payload)
        );
    }

    public function testTheSummarySaysWhenADimensionWasEmptied(): void
    {
        $payload = CurationEvent::build(['lrmi:teaches' => ['before' => [101], 'after' => []]]);

        $this->assertSame('Re-catalogación · lrmi:teaches −1 (vaciada)', CurationEvent::summary($payload));
    }

    public function testTheSummaryOfARevertSaysSo(): void
    {
        $payload = CurationEvent::build(
            ['lrmi:teaches' => ['before' => [305], 'after' => [101]]],
            '2026-07-30T10:31:00+00:00'
        );

        $this->assertStringStartsWith('Reversión · ', CurationEvent::summary($payload));
    }

    public function testAPayloadSurvivesTheRoundTripThroughRdf(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101, 102], 'after' => [305], 'why' => [101 => 'porqué con acentós']],
        ]);

        $this->assertSame($payload, CurationEvent::decode(CurationEvent::encode($payload)));
    }

    public function testAPayloadFromAFutureVersionIsRefusedInsteadOfMisread(): void
    {
        $json = '{"v":99,"op":"recatalog","undoOf":null,"terms":{"lrmi:teaches":{"before":[1],"after":[]}}}';

        $this->assertNull(CurationEvent::decode($json));
    }

    public function testGarbageIsRefused(): void
    {
        $this->assertNull(CurationEvent::decode('no soy json'));
        $this->assertNull(CurationEvent::decode('[]'));
        $this->assertNull(CurationEvent::decode('{"v":1}'));
    }

    public function testTheStateToRestoreIsThePreviousOneNotTheCurrentOne(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101, 102], 'after' => [305]],
            'schema:about' => ['before' => [], 'after' => [7]],
        ]);

        $this->assertSame(
            ['lrmi:teaches' => [101, 102], 'schema:about' => []],
            CurationEvent::restoreTargets($payload)
        );
    }

    public function testTheReasonsToRestoreComeBackKeyedByItemId(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101], 'after' => [], 'why' => [101 => 'porque sí']],
            'schema:about' => ['before' => [7], 'after' => []],
        ]);

        $this->assertSame(['lrmi:teaches' => [101 => 'porque sí']], CurationEvent::restoreReasons($payload));
    }

    public function testTheStateAfterTheEventIsWhatDetectsSomebodyElseTouchedTheItem(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => ['before' => [101], 'after' => [305, 306]],
        ]);

        $this->assertSame(['lrmi:teaches' => [305, 306]], CurationEvent::expectedTargets($payload));
    }

    public function testTypedEventKeepsValuesAndOrder(): void
    {
        $payload = CurationEvent::buildTyped([
            'dcterms:creator' => [
                'before' => [['type' => 'literal', 'value' => 'Ana']],
                'after' => [['type' => 'literal', 'value' => 'Ana'], ['type' => 'literal', 'value' => 'Luis']],
            ],
        ]);

        $this->assertSame(2, $payload['v']);
        $this->assertSame('governance', $payload['op']);
        $this->assertSame(
            [['type' => 'literal', 'value' => 'Ana'], ['type' => 'literal', 'value' => 'Luis']],
            $payload['terms']['dcterms:creator']['after']
        );
    }

    public function testReorderingAuthorsIsAChange(): void
    {
        $payload = CurationEvent::buildTyped([
            'dcterms:creator' => [
                'before' => [['type' => 'literal', 'value' => 'Ana'], ['type' => 'literal', 'value' => 'Luis']],
                'after' => [['type' => 'literal', 'value' => 'Luis'], ['type' => 'literal', 'value' => 'Ana']],
            ],
        ]);

        $this->assertNotNull($payload);
    }

    public function testTypedEventWithNoChangeIsNotAnEvent(): void
    {
        $payload = CurationEvent::buildTyped([
            'dcterms:license' => [
                'before' => [['type' => 'customvocab:2', 'uri' => 'https://x/by/4.0/', 'label' => 'CC BY']],
                'after' => [['type' => 'customvocab:2', 'uri' => 'https://x/by/4.0/', 'label' => 'CC BY']],
            ],
        ]);

        $this->assertNull($payload);
    }

    public function testDecodeAcceptsBothVersionsAndRejectsOthers(): void
    {
        $v1 = CurationEvent::encode(CurationEvent::build([
            'lrmi:teaches' => ['before' => [], 'after' => [7]],
        ]));
        $v2 = CurationEvent::encode(CurationEvent::buildTyped([
            'dcterms:creator' => ['before' => [], 'after' => [['type' => 'literal', 'value' => 'Ana']]],
        ]));

        $this->assertSame(1, CurationEvent::decode($v1)['v']);
        $this->assertSame(2, CurationEvent::decode($v2)['v']);
        $this->assertNull(CurationEvent::decode('{"v":3,"op":"x","terms":{"a":{"before":[],"after":[]}}}'));
        $this->assertNull(CurationEvent::decode('not json'));
    }

    public function testScopeComesFromTheTermsNotTheVersion(): void
    {
        $governance = CurationEvent::buildTyped([
            'dcterms:license' => ['before' => [], 'after' => [['type' => 'uri', 'uri' => 'https://x/by/4.0/']]],
        ]);
        $curriculum = CurationEvent::build(['lrmi:teaches' => ['before' => [], 'after' => [7]]]);

        $this->assertSame('governance', CurationEvent::scopeOf($governance));
        $this->assertSame('curriculum', CurationEvent::scopeOf($curriculum));
    }

    public function testRestoreAndExpectedValuesRoundTrip(): void
    {
        $payload = CurationEvent::buildTyped([
            'dcterms:license' => [
                'before' => [['type' => 'customvocab:2', 'uri' => 'https://x/by/4.0/', 'label' => 'CC BY']],
                'after' => [],
            ],
        ]);
        $decoded = CurationEvent::decode(CurationEvent::encode($payload));

        $this->assertSame(
            [['type' => 'customvocab:2', 'uri' => 'https://x/by/4.0/', 'label' => 'CC BY']],
            CurationEvent::restoreValues($decoded)['dcterms:license']
        );
        $this->assertSame([], CurationEvent::expectedValues($decoded)['dcterms:license']);
    }
}
