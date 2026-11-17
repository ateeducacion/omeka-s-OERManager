<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\StatusGuard;
use PHPUnit\Framework\TestCase;

/**
 * Security review of TASK-067 slice 2: `curation:status`/`curation:note` are
 * plain literals, so anyone with item update could write «Publicado» by hand
 * and skip the curator. Only WorkflowService (a request-scoped flag) and
 * global_admin (initial import, TASK-072) may change them.
 */
final class StatusGuardTest extends TestCase
{
    private const BEFORE = ['curation:status' => ['Propuesto'], 'curation:note' => []];

    public function testUnchangedValuesAlwaysPass(): void
    {
        $guard = new StatusGuard();
        // The native form resubmits the values it shows; order does not matter.
        $this->assertFalse($guard->rejects(
            ['curation:status' => ['a', 'b']],
            ['curation:status' => ['b', 'a'], 'curation:note' => []],
            true,
            'rea_proponente'
        ));
    }

    public function testAHandMadeChangeIsRejectedForEveryoneButGlobalAdmin(): void
    {
        $guard = new StatusGuard();
        $after = ['curation:status' => ['Publicado'], 'curation:note' => []];
        foreach (['rea_proponente', 'rea_curador', 'site_editor', 'editor', 'site_admin', null] as $role) {
            $this->assertTrue($guard->rejects(self::BEFORE, $after, true, $role), (string) $role);
        }
        $this->assertFalse($guard->rejects(self::BEFORE, $after, true, 'global_admin'));
        // A note written by hand is a change too.
        $this->assertTrue($guard->rejects(self::BEFORE, array_merge(self::BEFORE, ['curation:note' => ['x']]), true, 'rea_curador'));
        $this->assertTrue($guard->rejects([], ['curation:status' => ['Publicado']], true, 'rea_proponente'));
    }

    public function testWorkflowWritesPassAndTheFlagIsScopedToTheWrite(): void
    {
        $guard = new StatusGuard();
        $after = ['curation:status' => ['Publicado']];
        $seen = $guard->run(fn () => $guard->rejects(self::BEFORE, $after, true, 'rea_curador'));
        $this->assertFalse($seen);
        $this->assertTrue($guard->rejects(self::BEFORE, $after, true, 'rea_curador'));
        try {
            $guard->run(static function (): void {
                throw new \RuntimeException('denied');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertTrue($guard->rejects(self::BEFORE, $after, true, 'rea_curador'), 'flag reset after a failure');
    }

    public function testOtherItemsAreNotGuarded(): void
    {
        $this->assertFalse((new StatusGuard())->rejects(self::BEFORE, [], false, 'editor'));
    }
}
