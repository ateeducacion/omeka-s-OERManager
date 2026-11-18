<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\StatusGuard;
use PHPUnit\Framework\TestCase;

/**
 * Security reviews of TASK-067: `curation:status`/`curation:note` are plain
 * values, so anyone with item update could write «Publicado» by hand, make
 * them public, reorder them or forge who published. Only WorkflowService (a
 * request-scoped flag) and global_admin (initial import, TASK-072) may change
 * them, and «change» means anything the module reads or trusts: order, type,
 * text, uri, language, linked resource, visibility and annotation.
 */
final class StatusGuardTest extends TestCase
{
    /** A canonical value, as both sides build it. */
    private static function v(string $text, array $overrides = [], array $annotation = []): array
    {
        return StatusGuard::canonical(
            $overrides['type'] ?? 'literal',
            $text,
            $overrides['uri'] ?? null,
            $overrides['lang'] ?? null,
            $overrides['resource'] ?? null,
            $overrides['public'] ?? false,
            $annotation
        );
    }

    private static function stamp(string $who = 'Cu', string $when = '2026-11-17T10:00:00.000000+00:00'): array
    {
        return [
            StatusGuard::canonical('literal', $who, null, null, null, false, [], 'dcterms:contributor'),
            StatusGuard::canonical('literal', $when, null, null, null, false, [], 'dcterms:modified'),
        ];
    }

    private function before(): array
    {
        return [
            'curation:status' => [self::v('Propuesto', [], self::stamp())],
            'curation:note' => [self::v('Motivo')],
        ];
    }

    public function testUnchangedValuesPass(): void
    {
        // The native form resubmits exactly what it shows.
        $this->assertFalse((new StatusGuard())->rejects($this->before(), $this->before(), true, 'rea_proponente'));
    }

    /** @return array<string, array<string, list<array>>> */
    private function tamperings(): array
    {
        $b = $this->before();
        $two = ['curation:status' => [self::v('A'), self::v('B')]];
        return [
            'text' => ['curation:status' => [self::v('Publicado', [], self::stamp())]] + $b,
            'visibility' => ['curation:status' => [self::v('Propuesto', ['public' => true], self::stamp())]] + $b,
            'note visibility' => ['curation:note' => [self::v('Motivo', ['public' => true])]] + $b,
            'contributor forged' => ['curation:status' => [self::v('Propuesto', [], self::stamp('Otro'))]] + $b,
            'modified forged' => ['curation:status' => [self::v('Propuesto', [], self::stamp('Cu', '2020-01-01'))]] + $b,
            'annotation removed' => ['curation:status' => [self::v('Propuesto')]] + $b,
            'type uri' => ['curation:status' => [self::v('Propuesto', ['type' => 'uri', 'uri' => 'x:y'], self::stamp())]]
                + $b,
            'type resource' => ['curation:status' => [self::v('', ['type' => 'resource:item', 'resource' => 9],
                self::stamp())]] + $b,
            'language' => ['curation:status' => [self::v('Propuesto', ['lang' => 'es'], self::stamp())]] + $b,
            'duplicate' => ['curation:status' => [self::v('Propuesto', [], self::stamp()),
                self::v('Propuesto', [], self::stamp())]] + $b,
            'note by hand' => ['curation:note' => [self::v('Otro motivo')]] + $b,
            'reorder' => [$two, ['curation:status' => [self::v('B'), self::v('A')]]],
        ];
    }

    public function testEveryTamperingIsRejectedForEveryoneButGlobalAdmin(): void
    {
        $guard = new StatusGuard();
        foreach ($this->tamperings() as $case => $after) {
            $before = $this->before();
            if ('reorder' === $case) {
                [$before, $after] = $after;
            }
            foreach (['rea_proponente', 'rea_curador', 'site_editor', 'editor', 'site_admin', null] as $role) {
                $this->assertTrue($guard->rejects($before, $after, true, $role), $case . ' / ' . (string) $role);
            }
            // Two status values are refused to everyone (see the next test).
            if (!in_array($case, ['duplicate', 'reorder'], true)) {
                $this->assertFalse($guard->rejects($before, $after, true, 'global_admin'), $case . ' / global_admin');
            }
        }
    }

    /** More than one status: the module reads only the first, so nobody adds a second. */
    public function testASecondStatusValueIsRejectedEvenForGlobalAdmin(): void
    {
        $after = $this->tamperings()['duplicate'];
        $this->assertTrue((new StatusGuard())->rejects($this->before(), $after, true, 'global_admin'));
        // Legacy data with two values is not blocked while it is left as it is.
        $this->assertFalse((new StatusGuard())->rejects($after, $after, true, 'rea_proponente'));
    }

    public function testWorkflowWritesPassAndTheFlagIsScopedToTheWrite(): void
    {
        $guard = new StatusGuard();
        $after = $this->tamperings()['text'];
        $this->assertFalse($guard->run(fn () => $guard->rejects($this->before(), $after, true, 'rea_curador')));
        $this->assertTrue($guard->rejects($this->before(), $after, true, 'rea_curador'));
        try {
            $guard->run(static function (): void {
                throw new \RuntimeException('denied');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertTrue($guard->rejects($this->before(), $after, true, 'rea_curador'), 'flag reset after a failure');
    }

    public function testOtherItemsAreNotGuarded(): void
    {
        $this->assertFalse((new StatusGuard())->rejects($this->before(), [], false, 'editor'));
    }

    /** The database side: rows ordered by value id, annotation rows attached by annotation id. */
    public function testDatabaseRowsAndEntityValuesCanonicaliseAlike(): void
    {
        $rows = [
            ['term' => 'status', 'type' => 'literal', 'value' => 'Propuesto', 'uri' => null, 'lang' => null,
                'value_resource_id' => null, 'is_public' => '0', 'value_annotation_id' => '40'],
            ['term' => 'note', 'type' => 'literal', 'value' => 'Motivo', 'uri' => null, 'lang' => null,
                'value_resource_id' => null, 'is_public' => '0', 'value_annotation_id' => null],
        ];
        $annotationRows = [
            ['resource_id' => '40', 'term' => 'dcterms:contributor', 'type' => 'literal', 'value' => 'Cu', 'uri' => null,
                'lang' => null, 'value_resource_id' => null, 'is_public' => '0'],
            ['resource_id' => '40', 'term' => 'dcterms:modified', 'type' => 'literal',
                'value' => '2026-11-17T10:00:00.000000+00:00', 'uri' => null, 'lang' => null,
                'value_resource_id' => null, 'is_public' => '0'],
        ];
        $this->assertSame($this->before(), StatusGuard::fromRows($rows, $annotationRows));
    }
}
