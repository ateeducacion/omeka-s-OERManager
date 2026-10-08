<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\CurriculumCycle;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision (2026-10-08): in Primaria a REA is aligned to the two courses
 * of its cycle, and knowledge/criteria are worked within the cycle, not with
 * course precision. Two leaves are cycle twins when only the course changes
 * inside the same cycle: PMAT03CE4.1 and PMAT04CE4.1 (2º ciclo).
 */
final class CurriculumCycleTest extends TestCase
{
    public function testPrimariaCoursesPairIntoThreeCycles(): void
    {
        self::assertSame('Primaria-1', CurriculumCycle::courseKey('1º Primaria'));
        self::assertSame('Primaria-1', CurriculumCycle::courseKey('2º Primaria'));
        self::assertSame('Primaria-2', CurriculumCycle::courseKey('3º Primaria'));
        self::assertSame('Primaria-2', CurriculumCycle::courseKey('4º Primaria'));
        self::assertSame('Primaria-3', CurriculumCycle::courseKey('6º Primaria'));
    }

    public function testInfantilCoursesFormTwoCyclesOfThree(): void
    {
        self::assertSame('Infantil-1', CurriculumCycle::courseKey('3º Infantil de 2 años'));
        self::assertSame('Infantil-2', CurriculumCycle::courseKey('4º Infantil de 3 años'));
        self::assertSame('Infantil-2', CurriculumCycle::courseKey('6º Infantil de 5 años'));
    }

    public function testOtherStagesHaveNoCycle(): void
    {
        self::assertNull(CurriculumCycle::courseKey('4º ESO'));
        self::assertNull(CurriculumCycle::courseKey('1º Bachillerato'));
        self::assertNull(CurriculumCycle::courseKey(''));
    }

    public function testCycleTwinLeavesShareTheirKey(): void
    {
        self::assertSame(CurriculumCycle::leafKey('PMAT03CE4.1'), CurriculumCycle::leafKey('PMAT04CE4.1'));
        self::assertSame(CurriculumCycle::leafKey('PC9N03SBIII.2.2'), CurriculumCycle::leafKey('PC9N04SBIII.2.2'));
        self::assertSame(CurriculumCycle::leafKey('IDE304SBI.3'), CurriculumCycle::leafKey('IDE306SBI.3'));
    }

    public function testLeavesOfOtherCyclesAreasOrStatementsDiffer(): void
    {
        self::assertNotSame(CurriculumCycle::leafKey('PMAT04CE4.1'), CurriculumCycle::leafKey('PMAT05CE4.1'));
        self::assertNotSame(CurriculumCycle::leafKey('PMAT04CE4.1'), CurriculumCycle::leafKey('PC9N04CE4.1'));
        self::assertNotSame(CurriculumCycle::leafKey('PMAT04CE4.1'), CurriculumCycle::leafKey('PMAT04CE4.2'));
        self::assertNotSame(CurriculumCycle::leafKey('PMAT04CE4.1'), CurriculumCycle::leafKey('PMAT04SB4.1'));
        self::assertNotSame(CurriculumCycle::leafKey('IDE303SBI.3'), CurriculumCycle::leafKey('IDE304SBI.3'));
    }

    /** The catalogue stores identifiers with a type prefix: «sb:PEUM02SBII.4», «crit:PMAT04CE4.1». */
    public function testTheCatalogueTypePrefixIsIgnored(): void
    {
        self::assertSame(CurriculumCycle::leafKey('PEUM01SBII.4'), CurriculumCycle::leafKey('sb:PEUM02SBII.4'));
        self::assertSame(CurriculumCycle::leafKey('crit:PMAT03CE4.1'), CurriculumCycle::leafKey('PMAT04CE4.1'));
        self::assertSame('STEE04SBIII.1', CurriculumCycle::leafKey('sb:STEE04SBIII.1'));
    }

    public function testLeavesWithoutACycleKeepTheirCode(): void
    {
        // ESO and anything unrecognised compare exactly, by their own code.
        self::assertSame('STEE04SBIII.1', CurriculumCycle::leafKey('STEE04SBIII.1'));
        self::assertSame('XYZ', CurriculumCycle::leafKey(' XYZ '));
    }
}
