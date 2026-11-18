<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\RecatalogRule;
use OERManager\Service\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

/**
 * Who may re-catalogue (curricular alignment and axes) by workflow state and
 * role: owner decision of 2026-11-18 (TASK-035 spec §2, ADR-0023 addendum).
 */
final class RecatalogRuleTest extends TestCase
{
    public function testProfilesFollowTheRoles(): void
    {
        $this->assertSame(RecatalogRule::CURATOR, RecatalogRule::profile('global_admin'));
        $this->assertSame(RecatalogRule::CURATOR, RecatalogRule::profile('rea_curador'));
        $this->assertSame(RecatalogRule::PROPOSER, RecatalogRule::profile('rea_proponente'));
        foreach ([null, '', 'site_admin', 'editor', 'site_editor', 'author', 'reviewer'] as $role) {
            $this->assertNull(RecatalogRule::profile($role), (string) $role);
        }
    }

    /**
     * The owner's whole table: [status, isPublic] => [curator, proposer owning the item].
     */
    public function testTheOwnersTableByStateAndProfile(): void
    {
        $cases = [
            'Borrador' => [null, false, null, null],
            'Propuesto' => [WorkflowStatus::PROPOSED, false, null, RecatalogRule::STATUS],
            'Rechazado' => [WorkflowStatus::REJECTED, false, null, null],
            'Publicado' => [WorkflowStatus::PUBLISHED, true, null, RecatalogRule::STATUS],
            'fuera del flujo (sin estado)' => [null, true, null, RecatalogRule::STATUS],
            'fuera del flujo (rechazado público)' => [WorkflowStatus::REJECTED, true, null, RecatalogRule::STATUS],
            'Propuesto público' => [WorkflowStatus::PROPOSED, true, null, RecatalogRule::STATUS],
            'Descartado' => [WorkflowStatus::DISCARDED, false, RecatalogRule::DISCARDED, RecatalogRule::DISCARDED],
            'Descartado público' => [WorkflowStatus::DISCARDED, true, RecatalogRule::DISCARDED, RecatalogRule::DISCARDED],
            'literal desconocido' => ['Otro', false, null, RecatalogRule::STATUS],
        ];
        foreach ($cases as $case => [$status, $isPublic, $curator, $proposer]) {
            $this->assertSame(
                $curator,
                RecatalogRule::denial($status, $isPublic, RecatalogRule::CURATOR, false, true),
                'curator: ' . $case
            );
            $this->assertSame(
                $proposer,
                RecatalogRule::denial($status, $isPublic, RecatalogRule::PROPOSER, true, true),
                'proposer: ' . $case
            );
        }
    }

    public function testAProposerOnlyTouchesTheirOwnItems(): void
    {
        $this->assertSame(
            RecatalogRule::NOT_OWNER,
            RecatalogRule::denial(null, false, RecatalogRule::PROPOSER, false, true)
        );
        // Ownership does not matter to a curator.
        $this->assertNull(RecatalogRule::denial(null, false, RecatalogRule::CURATOR, false, true));
    }

    public function testNativeUpdateAndAKnownProfileAreRequiredOfEveryone(): void
    {
        foreach ([RecatalogRule::CURATOR, RecatalogRule::PROPOSER] as $profile) {
            $this->assertSame(RecatalogRule::NO_UPDATE, RecatalogRule::denial(null, false, $profile, true, false));
        }
        $this->assertSame(RecatalogRule::ROLE, RecatalogRule::denial(null, false, null, true, true));
        // Descartado is named before the native permission: it is what the user can act on.
        $this->assertSame(
            RecatalogRule::DISCARDED,
            RecatalogRule::denial(WorkflowStatus::DISCARDED, false, RecatalogRule::CURATOR, true, false)
        );
    }

    /**
     * Security review of TASK-035: the panel and the history are read by a
     * proposer only on their own REA; curators and global_admin unchanged.
     */
    public function testAProposerReadsThePanelOnlyOfTheirOwnItems(): void
    {
        $this->assertTrue(RecatalogRule::readDenied(RecatalogRule::PROPOSER, false));
        $this->assertFalse(RecatalogRule::readDenied(RecatalogRule::PROPOSER, true));
        $this->assertFalse(RecatalogRule::readDenied(RecatalogRule::CURATOR, false));
        // Other roles are the ACL's business (it denies them the panel).
        $this->assertFalse(RecatalogRule::readDenied(null, false));
    }

    public function testEveryRefusalHasAMessage(): void
    {
        foreach (
            [RecatalogRule::ROLE, RecatalogRule::DISCARDED, RecatalogRule::NO_UPDATE,
            RecatalogRule::NOT_OWNER, RecatalogRule::STATUS] as $code
        ) {
            $this->assertNotSame('', RecatalogRule::message($code), $code);
        }
        $this->assertSame('', RecatalogRule::message(null));
        $this->assertSame('', RecatalogRule::message('unknown'));
    }
}
