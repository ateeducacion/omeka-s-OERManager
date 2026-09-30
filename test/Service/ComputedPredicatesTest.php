<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ComputedPredicates;
use OERManager\Service\Governance\AlignmentStatusValue;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\IntegrityResult;
use Omeka\Api\Representation\ItemRepresentation;
use PHPUnit\Framework\TestCase;

/**
 * El controlador tenía una rama if/else escrita a medida del filtro «parcial».
 * Con un segundo filtro computado esa rama se duplicaría entera —búsqueda con
 * tope, ComputedFilter, paginator, isTruncated—, así que se resuelve antes qué
 * predicados pide la query y el controlador se queda con UNA rama.
 */
final class ComputedPredicatesTest extends TestCase
{
    public function testNoFiltersMeansNoComputedWork(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys([]));
    }

    public function testPartialAlignmentIsComputed(): void
    {
        $this->assertSame(
            [ComputedPredicates::ALIGNMENT_PARTIAL],
            ComputedPredicates::activeKeys(['alignment' => AlignmentStatusValue::PARTIAL])
        );
    }

    /** Los otros dos estados de anclaje SÍ se expresan como query (MasterViewQuery). */
    public function testCompleteAndNoneAlignmentAreNotComputed(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys(['alignment' => 'complete']));
        $this->assertSame([], ComputedPredicates::activeKeys(['alignment' => 'none']));
    }

    public function testIntegrityFilterIsComputed(): void
    {
        $this->assertSame(
            [ComputedPredicates::INTEGRITY],
            ComputedPredicates::activeKeys(['integrity' => 'warning'])
        );
        $this->assertSame(
            [ComputedPredicates::INTEGRITY],
            ComputedPredicates::activeKeys(['integrity' => 'ok'])
        );
    }

    /**
     * D-5: el semáforo pinta dos estados porque «error» no tiene productor —el
     * FK del core cascadea al borrar, así que el enlace muerto es casi
     * inalcanzable—. Pedirlo por URL no debe disparar un barrido inútil.
     */
    public function testUnknownIntegrityValueIsIgnored(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys(['integrity' => 'error']));
        $this->assertSame([], ComputedPredicates::activeKeys(['integrity' => 'cualquiera']));
        $this->assertSame([], ComputedPredicates::activeKeys(['integrity' => '']));
    }

    public function testBothFiltersCombineInAStableOrder(): void
    {
        $this->assertSame(
            [ComputedPredicates::ALIGNMENT_PARTIAL, ComputedPredicates::INTEGRITY],
            ComputedPredicates::activeKeys(['integrity' => 'ok', 'alignment' => AlignmentStatusValue::PARTIAL])
        );
    }

    public function testArrayValuesFromTheQueryStringDoNotExplode(): void
    {
        $this->assertSame([], ComputedPredicates::activeKeys([
            'alignment' => ['partial'],
            'integrity' => ['ok'],
        ]));
    }

    /**
     * `AlignmentStatusValue` es la única fuente de verdad de estos literales
     * (ver docblock de la clase): fijamos aquí los valores esperados para que un cambio
     * accidental lo detecte el test, sin poder comparar contra `AlignmentStatus` —esa clase
     * implementa una interfaz del core y no puede cargarse en código puro de host.
     */
    public function testAlignmentStatusValueLiteralsAreStable(): void
    {
        $this->assertSame('complete', AlignmentStatusValue::COMPLETE);
        $this->assertSame('partial', AlignmentStatusValue::PARTIAL);
        $this->assertSame('none', AlignmentStatusValue::NONE);
    }

    public function testPredicateMatchesTheRequestedIntegrityStatus(): void
    {
        $ok = $this->createMock(ItemRepresentation::class);
        $warn = $this->createMock(ItemRepresentation::class);
        $checker = $this->createMock(IntegrityChecker::class);
        $checker->method('check')->willReturnCallback(
            fn ($item) => new IntegrityResult($item === $warn
                ? [['severity' => 'warning', 'code' => 'missing_license', 'field' => 'dcterms:license', 'message' => 'x']]
                : [])
        );
        $predicate = ComputedPredicates::predicate(
            [ComputedPredicates::INTEGRITY],
            [1 => $ok, 2 => $warn],
            ['integrity' => 'warning'],
            $checker
        );

        $this->assertFalse($predicate(1));
        $this->assertTrue($predicate(2));
    }

    public function testNoKeysMatchesEverything(): void
    {
        $predicate = ComputedPredicates::predicate([], [], [], $this->createMock(IntegrityChecker::class));

        $this->assertTrue($predicate(99));
    }
}
