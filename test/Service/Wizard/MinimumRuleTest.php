<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\MinimumRule;
use PHPUnit\Framework\TestCase;

final class MinimumRuleTest extends TestCase
{
    private static function complete(): array
    {
        return [
            'resource' => true,
            'values' => ['dcterms:title' => ['Fracciones'], 'lrmi:learningResourceType' => ['Vídeo'],
                'dcterms:license' => ['https://creativecommons.org/licenses/by-sa/4.0/']],
            'alignment' => ['lrmi:educationalLevel' => [11], 'schema:about' => [21], 'lrmi:teaches' => [31]],
        ];
    }

    public function testACompleteStateMissesNothing(): void
    {
        $this->assertSame([], MinimumRule::missing(self::complete(), ['dcterms:title', 'lrmi:learningResourceType']));
    }

    public function testAnEmptyStateMissesEverythingInOrder(): void
    {
        $this->assertSame(
            ['resource', 'field:dcterms:title', 'field:lrmi:learningResourceType', 'licence', 'course', 'subject'],
            MinimumRule::missing(['resource' => false, 'values' => [], 'alignment' => []], ['dcterms:title', 'lrmi:learningResourceType'])
        );
    }

    public function testBlankValuesDoNotCount(): void
    {
        $state = self::complete();
        $state['values']['dcterms:title'] = ['  ', ''];
        $this->assertSame(['field:dcterms:title'], MinimumRule::missing($state, ['dcterms:title']));
    }

    public function testRequiredAnchoringTermsUseTheirOwnCodes(): void
    {
        $state = self::complete();
        $state['alignment'] = [];
        $this->assertSame(
            ['course', 'subject', 'field:lrmi:teaches'],
            MinimumRule::missing($state, ['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches'])
        );
    }

    /** Owner's report on PR #100: a template that does not require the title still cannot propose without one. */
    public function testTheTitleIsAlwaysRequiredAndListedOnce(): void
    {
        $state = self::complete();
        $state['values']['dcterms:title'] = [' '];
        $this->assertSame(['field:dcterms:title'], MinimumRule::missing($state, ['lrmi:learningResourceType']));
        $this->assertSame(['field:dcterms:title'], MinimumRule::missing($state, ['dcterms:title']));
    }

    public function testTheLicenceIsAlwaysRequiredAndListedOnce(): void
    {
        $state = self::complete();
        unset($state['values']['dcterms:license']);
        $this->assertSame(['licence'], MinimumRule::missing($state, ['dcterms:license']));
        $this->assertSame(['licence'], MinimumRule::missing($state, []));
    }

    public function testMeetsTemplateLooksOnlyAtTheRequiredTerms(): void
    {
        $state = self::complete();
        $this->assertTrue(MinimumRule::meetsTemplate($state, ['dcterms:title', 'lrmi:teaches']));
        $this->assertFalse(MinimumRule::meetsTemplate($state, ['dcterms:creator']));
        $this->assertFalse(MinimumRule::meetsTemplate($state, ['lrmi:assesses']));
    }
}
