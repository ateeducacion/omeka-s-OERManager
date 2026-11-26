# TASK-011 Batch AI Proposal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A curator launches the AI proposal on part of the curation queue as a background Job; each proposal is stored in its REA as a private `curation:data` value, the queue filters by it, and the detail panel pre-fills it until the curator applies, publishes or discards.

**Architecture:** A pure codec (`StoredProposal`) defines the stored value. An entity writer (`StoredProposalWriter`) and a small store (`StoredProposals`) write it directly on the `Item` entity, with no API, so imported REA that fail template validation still get it. `BatchProposeEligibility` sorts a selection into «entran / fuera de la cola / ya tienen propuesta». `AiBatchProposeJob::run()` is a pure loop with injected callables. `AiBatchController` follows `GovernanceBatchController` (POST + CSRF, `BatchSelection`, `BatchJobLookup`, progress in `ProposalStore`). The panel receives the proposal in `data-recatalog` and pre-fills the alignment editor through the existing `proposeIntoEditor` path.

**Tech Stack:** PHP 8.4 (Omeka S 4.2, Laminas, Doctrine), PHPUnit with the host stubs in `test/stubs/`, ES modules tested with `node --test`.

**Spec:** [`docs/superpowers/specs/2026-11-25-task-011-batch-ai-proposal-design.md`](../specs/2026-11-25-task-011-batch-ai-proposal-design.md)

## Global Constraints

- Target PHP 8.4; PSR-12 (`make lint`); no PHPStan or Psalm; do not change the Makefile.
- No custom Doctrine tables (NFR-002): the proposal is a native `Value` of `curation:data`.
- Marker, stable and untranslatable: `OERManager/ai-proposal/1`; the value is **private** (`is_public = false`), a `literal`, at most one per REA.
- Only values whose text starts with the marker are read, replaced or removed; other `curation:data` values are never touched.
- The Job never writes alignment, axes, status or visibility (ADR-0007); it only stores a proposal.
- One AI batch at a time; abort after 5 LLM errors in a row; cost estimate 0.0052 USD per REA.
- Only `rea_curador` and `global_admin` reach the batch and the stored proposal (ADR-0023 §1).
- UI copy in Spanish through `$translate` / `Omeka.jsTranslate`; code, comments, docs and commits in English.
- Never log the LLM key or prompts. Log item id and the exception message only.
- Commits: one per task, message in English, no `Co-Authored-By` trailer; name the model in a plain line of the body if an AI wrote it.
- Before each commit: `make lint`, `make test`, `make test-js`; line coverage of `src/` stays ≥ 90 %.
- Container harnesses write the catalogue: run `batch-ai-check.php` only on a development or disposable catalogue.

## Spec rulings made while planning

Recorded here and applied to the spec in Task 11:

1. **No `EntityManager::clear()` in the Job.** `GovernanceBatchJob` documents why: clearing detaches the authenticated owner and the next flush fails. Memory was measured at about 12 KB per REA. The spec's «clear every 50» is dropped.
2. **The container harness runs the real `perform()` with a fake `ProposeRunner`** (built from the host test fakes and injected with `setAllowOverride`), instead of a fake LLM provider. It costs nothing and exercises the real wiring.
3. **Removal hooks live in `IndexController`** (`recatalog-apply`, `publish-proposal`, `discard`, `ai-proposal-discard`), after the action succeeds. Those are the only entry points, and the services stay unaware of proposals.
4. **The panel's proposal travels in `data-recatalog`** (`recatalogPanel()`), not in `ItemPanelData`. It is the payload the anchor slot already parses. It is sent only to users allowed `ai-propose` (curators); a proposer never sees it.

## Review Focus

1. **A curator re-launches on the same selection while a batch runs.** Expected: `launch` answers `running` and nothing is dispatched (Task 5 test `testLaunchIsRefusedWhileABatchRuns`).
2. **A REA leaves the queue, or gets a proposal from elsewhere, between preview and Job.** Expected: the Job skips it without calling the LLM (Task 4 test `testAReaThatIsNoLongerEligibleIsSkippedWithoutCallingTheLlm`).
3. **A proposer opens their own REA that holds a proposal.** Expected: no `aiProposal` in `data-recatalog` (Task 7 test `testTheStoredProposalReachesOnlyUsersAllowedToProposeWithAi`).
4. **The stored text was edited by hand in the native form and no longer decodes.** Expected: the panel shows no proposal, the REA still counts as «con propuesta» (marker present) and the removal events still delete it (Task 2 test `testACorruptProposalIsNotReadButIsRemoved`).
5. **The removal after a successful publish throws.** Expected: the publish response is still a success; the error is logged (Task 7 test `testARemovalFailureNeverBreaksTheAction`).

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Service/Ai/StoredProposal.php` (new) | Pure codec: marker, encode/decode, payload → proposal, «changed», panel view, search row. |
| `src/Service/Ai/StoredProposalWriter.php` (new) | Read, replace and remove the marker values on an `Item` entity (duck-typed). |
| `src/Service/Ai/StoredProposals.php` (new) | By item id: `store`, `remove`, `mayUpdate`, with find + flush on the EntityManager. |
| `src/Service/Ai/BatchProposeEligibility.php` (new) | Splits ids into enter / out of queue / has proposal; `stillEligible`. |
| `src/Service/Governance/BatchJobLookup.php` | `+ isActive(string $class)`. |
| `src/Job/AiBatchProposeJob.php` (new) | Static `run()` loop and thin `perform()`. |
| `src/Controller/Admin/AiBatchController.php` (new) | `form`, `preview`, `launch`, `status`, `cancel`. |
| `view/oer-manager/admin/ai-batch/form.phtml` (new) | The sidebar markup and its data attributes. |
| `src/Permissions/ReaAcl.php` | Grants for `AiBatchController` and `ai-proposal-discard`. |
| `config/module.config.php` | Factories, controller and route `admin/oer-manager-ai-batch`. |
| `src/Service/MasterViewQuery.php`, `Module.php`, `view/.../index.phtml`, `view/.../search.phtml`, `asset/css/oer-workflow-badge.css` | The «Propuesta IA» filter, its chip and the row badge «IA». |
| `src/Controller/Admin/IndexController.php`, `view/.../panel-config.phtml`, `asset/js/config.js` | `aiProposal` in the panel data, `ai-proposal-discard`, removal hooks, batch button flag. |
| `asset/js/core/aiBatchModel.js` (new), `asset/js/ui/aiBatch.js` (new), `asset/js/ui/governanceBatch.js`, `asset/js/main.js` | The batch sidebar. |
| `asset/js/core/anchorData.js`, `asset/js/ui/aiPropose.js`, `asset/js/ui/recatalog.js` | Pre-fill on panel open, notice and «Descartar propuesta». |
| `test/container/batch-ai-check.php` (new) | End-to-end check on a development catalogue, no LLM cost. |
| `docs/…` | ADR-0007 addendum 1, spec rulings, backlog, traceability, project memory. |

Run a single PHP test class with `vendor/bin/phpunit -c test/phpunit.xml --filter <ClassName>` and a JS file with `node --test test/js/<file>.test.js`.

---

### Task 1: The stored proposal codec

**Files:**
- Create: `src/Service/Ai/StoredProposal.php`
- Test: `test/Service/Ai/StoredProposalTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces (all `public static`, class `OERManager\Service\Ai\StoredProposal`, constants `TERM = 'curation:data'`, `MARKER = 'OERManager/ai-proposal/1'`, `VERSION = 1`):
  - `isProposal(string $text): bool`
  - `encode(array $proposal): string`; `decode(string $text): ?array`. A proposal is `array{created:string, model:string, item_modified:?string, alignment:array<string,list<int>>, justifications:array<string,array<int,string>>}`, keys in that order.
  - `fromPayload(array $payload, string $model, \DateTimeInterface $now, ?\DateTimeInterface $itemModified): array`, where `$payload` is `ProposeRunner::run()`'s array.
  - `changed(array $proposal, ?\DateTimeInterface $modified): bool`
  - `firstOf(iterable $texts): ?array`; `anyMarked(iterable $texts): bool`
  - `searchRow(string $type): array{property:string,type:string,text:string}`
  - `forPanel(array $proposal, ?\DateTimeInterface $modified, callable $title): array{created:string, changed:bool, alignment:array<string,list<array{id:int,title:string}>>, justifications:array}`, where `$title` is `callable(int):?string` (null = term gone).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\StoredProposal;
use PHPUnit\Framework\TestCase;

final class StoredProposalTest extends TestCase
{
    private static function proposal(): array
    {
        return [
            'created' => '2026-11-25T10:31:07+00:00',
            'model' => 'claude-test',
            'item_modified' => '2026-11-20T08:00:00+00:00',
            'alignment' => ['lrmi:teaches' => [123, 456], 'dcterms:relation' => [42]],
            'justifications' => ['lrmi:teaches' => [123 => 'Porque sí']],
        ];
    }

    public function testARoundTripKeepsTheProposal(): void
    {
        $text = StoredProposal::encode(self::proposal());
        $this->assertStringStartsWith('OERManager/ai-proposal/1 {"v":1,', $text);
        $this->assertTrue(StoredProposal::isProposal($text));
        $this->assertSame(self::proposal(), StoredProposal::decode($text));
    }

    public function testAnEmptyProposalStaysAnObjectAndRoundTrips(): void
    {
        $empty = ['alignment' => [], 'justifications' => []] + self::proposal();
        $text = StoredProposal::encode($empty);
        $this->assertStringContainsString('"alignment":{}', $text);
        $this->assertStringContainsString('"justifications":{}', $text);
        $this->assertSame($empty, StoredProposal::decode($text));
    }

    public function testIdsAreCleaned(): void
    {
        $dirty = self::proposal();
        $dirty['alignment'] = ['lrmi:teaches' => [5, '5', 0, -1, 'x', 7], 'lrmi:assesses' => []];
        $this->assertSame(['lrmi:teaches' => [5, 7]], StoredProposal::decode(StoredProposal::encode($dirty))['alignment']);
    }

    public function testWhatIsNotAReadableProposalDecodesToNull(): void
    {
        foreach ([
            '',
            'Una nota cualquiera',
            'OERManager/ai-proposal/1',
            'OERManager/ai-proposal/1 {roto',
            'OERManager/ai-proposal/1 []',
            'OERManager/ai-proposal/1 {"v":2,"created":"x","alignment":{}}',
            'OERManager/ai-proposal/1 {"v":1,"alignment":{}}',
            'OERManager/ai-proposal/1 {"v":1,"created":"x","alignment":"no"}',
        ] as $text) {
            $this->assertNull(StoredProposal::decode($text), $text);
        }
        $this->assertFalse(StoredProposal::isProposal('Una nota'));
        $this->assertFalse(StoredProposal::isProposal('OERManager/ai-proposal/1'));
        $this->assertTrue(StoredProposal::isProposal('OERManager/ai-proposal/1 {roto'));
    }

    public function testFromPayloadKeepsTheIdsOfTheRunnerChipsAndDropsTheDebug(): void
    {
        $payload = [
            'alignment' => [
                'lrmi:teaches' => [['id' => 123, 'title' => 'A'], ['id' => 456, 'title' => 'B']],
                'dcterms:relation' => [['id' => 42, 'title' => 'Eje']],
            ],
            'justifications' => ['lrmi:teaches' => [123 => 'Porque sí']],
            'content' => ['truncated' => false],
            'debug' => ['content_text' => str_repeat('x', 1000)],
        ];
        $proposal = StoredProposal::fromPayload(
            $payload,
            'claude-test',
            new \DateTimeImmutable('2026-11-25T10:31:07+00:00'),
            new \DateTimeImmutable('2026-11-20T08:00:00+00:00')
        );
        $this->assertSame(self::proposal(), $proposal);
        $this->assertStringNotContainsString('content_text', StoredProposal::encode($proposal));
    }

    public function testChangedComparesTheItemModificationDate(): void
    {
        $proposal = self::proposal();
        $this->assertFalse(StoredProposal::changed($proposal, new \DateTimeImmutable('2026-11-20T08:00:00+00:00')));
        $this->assertTrue(StoredProposal::changed($proposal, new \DateTimeImmutable('2026-11-21T08:00:00+00:00')));
        $this->assertFalse(StoredProposal::changed($proposal, null));
        $proposal['item_modified'] = null;
        $this->assertTrue(StoredProposal::changed($proposal, new \DateTimeImmutable('2026-11-21T08:00:00+00:00')));
    }

    public function testFirstReadableAndAnyMarked(): void
    {
        $text = StoredProposal::encode(self::proposal());
        $this->assertSame(self::proposal(), StoredProposal::firstOf(['otra cosa', 'OERManager/ai-proposal/1 {roto', $text]));
        $this->assertNull(StoredProposal::firstOf(['otra cosa']));
        $this->assertTrue(StoredProposal::anyMarked(['x', 'OERManager/ai-proposal/1 {roto']));
        $this->assertFalse(StoredProposal::anyMarked(['x']));
    }

    public function testSearchRowMatchesTheMarker(): void
    {
        $this->assertSame(
            ['property' => 'curation:data', 'type' => 'sw', 'text' => 'OERManager/ai-proposal/1'],
            StoredProposal::searchRow('sw')
        );
    }

    public function testForPanelResolvesTitlesAndDropsTermsThatAreGone(): void
    {
        $titles = [123 => 'Saber A', 42 => 'Eje'];
        $view = StoredProposal::forPanel(
            self::proposal(),
            new \DateTimeImmutable('2026-11-21T08:00:00+00:00'),
            static fn (int $id): ?string => $titles[$id] ?? null
        );
        $this->assertSame([
            'created' => '2026-11-25T10:31:07+00:00',
            'changed' => true,
            'alignment' => [
                'lrmi:teaches' => [['id' => 123, 'title' => 'Saber A']],
                'dcterms:relation' => [['id' => 42, 'title' => 'Eje']],
            ],
            'justifications' => ['lrmi:teaches' => [123 => 'Porque sí']],
        ], $view);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter StoredProposalTest`
Expected: FAIL with `Class "OERManager\Service\Ai\StoredProposal" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

/**
 * TASK-011: the pending AI proposal stored in the REA until a curator reviews
 * it (ADR-0007 addendum 1). One private `curation:data` literal: the stable,
 * untranslatable marker, a space and a versioned JSON object. Pure: no Omeka.
 *
 * No `debug` and no titles are stored: the prompts weigh tens of KB, and
 * titles are resolved when the panel opens, so a renamed term shows its
 * current title.
 */
final class StoredProposal
{
    public const TERM = 'curation:data';
    public const MARKER = 'OERManager/ai-proposal/1';
    public const VERSION = 1;

    public static function isProposal(string $text): bool
    {
        return str_starts_with($text, self::MARKER . ' ');
    }

    /** @param array<string,mixed> $proposal */
    public static function encode(array $proposal): string
    {
        $justifications = self::justifications((array) ($proposal['justifications'] ?? []));
        $data = [
            'v' => self::VERSION,
            'created' => (string) $proposal['created'],
            'model' => (string) ($proposal['model'] ?? ''),
            'item_modified' => $proposal['item_modified'] ?? null,
            // Objects, never lists: an empty map must stay `{}`, and a map of
            // ids must not turn into a list when its keys happen to be 0..n.
            'alignment' => (object) self::alignment((array) ($proposal['alignment'] ?? [])),
            'justifications' => (object) array_map(static fn (array $map): object => (object) $map, $justifications),
        ];
        return self::MARKER . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed>|null */
    public static function decode(string $text): ?array
    {
        if (!self::isProposal($text)) {
            return null;
        }
        try {
            $data = json_decode(substr($text, strlen(self::MARKER) + 1), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
        if (
            !is_array($data)
            || self::VERSION !== ($data['v'] ?? null)
            || !is_string($data['created'] ?? null)
            || !is_array($data['alignment'] ?? null)
        ) {
            return null;
        }
        return [
            'created' => $data['created'],
            'model' => is_string($data['model'] ?? null) ? $data['model'] : '',
            'item_modified' => is_string($data['item_modified'] ?? null) ? $data['item_modified'] : null,
            'alignment' => self::alignment($data['alignment']),
            'justifications' => self::justifications(
                is_array($data['justifications'] ?? null) ? $data['justifications'] : []
            ),
        ];
    }

    /**
     * @param array<string,mixed> $payload ProposeRunner::run(): alignment as `{id,title}` chips
     * @return array<string,mixed>
     */
    public static function fromPayload(
        array $payload,
        string $model,
        \DateTimeInterface $now,
        ?\DateTimeInterface $itemModified
    ): array {
        $alignment = [];
        foreach ((array) ($payload['alignment'] ?? []) as $term => $chips) {
            foreach ((array) $chips as $chip) {
                $alignment[$term][] = is_array($chip) ? ($chip['id'] ?? 0) : $chip;
            }
        }
        return [
            'created' => $now->format(DATE_ATOM),
            'model' => $model,
            'item_modified' => $itemModified?->format(DATE_ATOM),
            'alignment' => self::alignment($alignment),
            'justifications' => self::justifications((array) ($payload['justifications'] ?? [])),
        ];
    }

    /** True when the REA was modified after the proposal was made. */
    public static function changed(array $proposal, ?\DateTimeInterface $modified): bool
    {
        if (null === $modified) {
            return false;
        }
        $then = $proposal['item_modified'] ?? null;
        return null === $then || $modified > new \DateTimeImmutable((string) $then);
    }

    /** @param iterable<string> $texts */
    public static function firstOf(iterable $texts): ?array
    {
        foreach ($texts as $text) {
            $proposal = self::decode((string) $text);
            if (null !== $proposal) {
                return $proposal;
            }
        }
        return null;
    }

    /** @param iterable<string> $texts */
    public static function anyMarked(iterable $texts): bool
    {
        foreach ($texts as $text) {
            if (self::isProposal((string) $text)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{property:string,type:string,text:string} a native item search row on the marker */
    public static function searchRow(string $type): array
    {
        return ['property' => self::TERM, 'type' => $type, 'text' => self::MARKER];
    }

    /**
     * @param callable(int):?string $title null when the term no longer exists
     * @return array<string,mixed>
     */
    public static function forPanel(array $proposal, ?\DateTimeInterface $modified, callable $title): array
    {
        $alignment = [];
        foreach ($proposal['alignment'] as $term => $ids) {
            foreach ($ids as $id) {
                $text = $title($id);
                if (null !== $text) {
                    $alignment[$term][] = ['id' => $id, 'title' => $text];
                }
            }
        }
        return [
            'created' => $proposal['created'],
            'changed' => self::changed($proposal, $modified),
            'alignment' => $alignment,
            'justifications' => $proposal['justifications'],
        ];
    }

    /** @return array<string,list<int>> positive, unique ids; empty terms dropped */
    private static function alignment(array $alignment): array
    {
        $clean = [];
        foreach ($alignment as $term => $ids) {
            if (!is_string($term) || !is_array($ids)) {
                continue;
            }
            $list = [];
            foreach ($ids as $id) {
                $id = is_numeric($id) ? (int) $id : 0;
                if ($id > 0 && !in_array($id, $list, true)) {
                    $list[] = $id;
                }
            }
            if ($list) {
                $clean[$term] = $list;
            }
        }
        return $clean;
    }

    /** @return array<string,array<int,string>> */
    private static function justifications(array $justifications): array
    {
        $clean = [];
        foreach ($justifications as $term => $map) {
            if (!is_string($term) || !is_array($map)) {
                continue;
            }
            foreach ($map as $id => $text) {
                if (is_int($id) && $id > 0 && is_string($text) && '' !== $text) {
                    $clean[$term][$id] = $text;
                }
            }
        }
        return $clean;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter StoredProposalTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
make lint
git add src/Service/Ai/StoredProposal.php test/Service/Ai/StoredProposalTest.php
git commit -m "feat: codec for the AI proposal stored in the REA (TASK-011)"
```

---

### Task 2: Write the proposal on the entity

**Files:**
- Create: `src/Service/Ai/StoredProposalWriter.php`, `src/Service/Ai/StoredProposals.php`
- Modify: `config/module.config.php` (two factories in `service_manager.factories`)
- Test: `test/Service/Ai/StoredProposalWriterTest.php`, `test/Service/Ai/StoredProposalsTest.php`

**Interfaces:**
- Consumes: `StoredProposal` (Task 1); `OERManager\Service\Workflow\StatusGuard::term(?object): ?string`.
- Produces:
  - `class StoredProposalWriter` (not final), constructor `(\Closure $property, \Closure $newValue)`; methods `read(object $entity): ?array`, `has(object $entity): bool`, `replace(object $entity, array $proposal): bool`, `remove(object $entity): bool`.
  - `class StoredProposals` (not final), constructor `(object $entityManager, StoredProposalWriter $writer, ?\Closure $now = null)`; methods `store(int $itemId, array $payload, string $model): bool`, `remove(int $itemId): bool`, `mayUpdate(int $itemId, object $acl): bool`.

- [ ] **Step 1: Write the failing tests**

`test/Service/Ai/StoredProposalWriterTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\StoredProposal;
use OERManager\Service\Ai\StoredProposalWriter;
use PHPUnit\Framework\TestCase;

final class StoredProposalWriterTest extends TestCase
{
    public static function property(string $term): object
    {
        [$prefix, $local] = explode(':', $term);
        return new class ($prefix, $local) {
            public function __construct(private string $prefix, private string $local)
            {
            }
            public function getVocabulary(): object
            {
                $prefix = $this->prefix;
                return new class ($prefix) {
                    public function __construct(private string $prefix)
                    {
                    }
                    public function getPrefix(): string
                    {
                        return $this->prefix;
                    }
                };
            }
            public function getLocalName(): string
            {
                return $this->local;
            }
        };
    }

    public static function newValue(): object
    {
        return new class {
            public $resource;
            public $property;
            public ?string $type = null;
            public ?string $value = null;
            public bool $public = true;
            public function setResource($resource): void
            {
                $this->resource = $resource;
            }
            public function setProperty($property): void
            {
                $this->property = $property;
            }
            public function getProperty()
            {
                return $this->property;
            }
            public function setType($type): void
            {
                $this->type = $type;
            }
            public function getType(): ?string
            {
                return $this->type;
            }
            public function setValue($value): void
            {
                $this->value = $value;
            }
            public function getValue(): ?string
            {
                return $this->value;
            }
            public function setIsPublic($public): void
            {
                $this->public = (bool) $public;
            }
            public function isPublic(): bool
            {
                return $this->public;
            }
        };
    }

    public static function value(string $term, string $text): object
    {
        $value = self::newValue();
        $value->setProperty(self::property($term));
        $value->setType('literal');
        $value->setValue($text);
        return $value;
    }

    public static function entity(array $values, ?\DateTimeInterface $modified = null): object
    {
        return new class ($values, $modified) {
            public object $values;
            public function __construct(array $values, private ?\DateTimeInterface $modified)
            {
                $this->values = new class ($values) implements \IteratorAggregate {
                    public function __construct(public array $items)
                    {
                    }
                    public function add($value): void
                    {
                        $this->items[] = $value;
                    }
                    public function removeElement($value): void
                    {
                        $this->items = array_values(array_filter($this->items, static fn ($i) => $i !== $value));
                    }
                    public function getIterator(): \ArrayIterator
                    {
                        return new \ArrayIterator($this->items);
                    }
                };
            }
            public function getValues(): object
            {
                return $this->values;
            }
            public function getModified(): ?\DateTimeInterface
            {
                return $this->modified;
            }
        };
    }

    public static function writer(bool $installed = true): StoredProposalWriter
    {
        $property = $installed ? self::property(StoredProposal::TERM) : null;
        return new StoredProposalWriter(static fn () => $property, static fn () => self::newValue());
    }

    private static function proposal(string $created = '2026-11-25T10:31:07+00:00'): array
    {
        return ['created' => $created, 'model' => 'm', 'item_modified' => null,
            'alignment' => ['lrmi:teaches' => [1]], 'justifications' => []];
    }

    /** @return list<object> */
    private static function marked(object $entity): array
    {
        return array_values(array_filter(
            $entity->getValues()->items,
            static fn ($v) => StoredProposal::isProposal((string) $v->getValue())
        ));
    }

    public function testReplaceWritesOnePrivateLiteralAndKeepsEverythingElse(): void
    {
        $other = self::value(StoredProposal::TERM, 'Otro uso de curation:data');
        $title = self::value('dcterms:title', 'Un REA');
        $entity = self::entity([$other, $title, self::value(StoredProposal::TERM, StoredProposal::encode(self::proposal('2026-01-01T00:00:00+00:00')))]);

        $this->assertTrue(self::writer()->replace($entity, self::proposal()));

        $marked = self::marked($entity);
        $this->assertCount(1, $marked);
        $this->assertFalse($marked[0]->isPublic());
        $this->assertSame('literal', $marked[0]->getType());
        $this->assertSame($entity, $marked[0]->resource);
        $this->assertSame(self::proposal(), StoredProposal::decode($marked[0]->getValue()));
        $this->assertContains($other, $entity->getValues()->items);
        $this->assertContains($title, $entity->getValues()->items);
        $this->assertSame(self::proposal(), self::writer()->read($entity));
        $this->assertTrue(self::writer()->has($entity));
    }

    public function testRemoveTouchesOnlyTheMarkerValues(): void
    {
        $other = self::value(StoredProposal::TERM, 'Otro uso de curation:data');
        $entity = self::entity([$other, self::value(StoredProposal::TERM, StoredProposal::encode(self::proposal()))]);
        $this->assertTrue(self::writer()->remove($entity));
        $this->assertSame([$other], $entity->getValues()->items);
        $this->assertFalse(self::writer()->remove($entity));
        $this->assertNull(self::writer()->read($entity));
    }

    public function testACorruptProposalIsNotReadButIsRemoved(): void
    {
        $entity = self::entity([self::value(StoredProposal::TERM, StoredProposal::MARKER . ' {roto')]);
        $this->assertNull(self::writer()->read($entity));
        $this->assertTrue(self::writer()->has($entity));
        $this->assertTrue(self::writer()->remove($entity));
        $this->assertSame([], $entity->getValues()->items);
    }

    public function testTheMarkerInAnotherPropertyIsIgnored(): void
    {
        $entity = self::entity([self::value('dcterms:description', StoredProposal::encode(self::proposal()))]);
        $this->assertFalse(self::writer()->has($entity));
        $this->assertFalse(self::writer()->remove($entity));
    }

    public function testWithoutTheCurationVocabularyNothingIsWritten(): void
    {
        $entity = self::entity([]);
        $this->assertFalse(self::writer(false)->replace($entity, self::proposal()));
        $this->assertSame([], $entity->getValues()->items);
    }
}
```

`test/Service/Ai/StoredProposalsTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\StoredProposal;
use OERManager\Service\Ai\StoredProposals;
use PHPUnit\Framework\TestCase;

final class StoredProposalsTest extends TestCase
{
    private function entityManager(?object $item): object
    {
        return new class ($item) {
            public int $flushes = 0;
            public array $asked = [];
            public function __construct(private ?object $item)
            {
            }
            public function find(string $class, int $id): ?object
            {
                $this->asked[] = [$class, $id];
                return $this->item;
            }
            public function flush(): void
            {
                $this->flushes++;
            }
        };
    }

    private function store(object $em): StoredProposals
    {
        return new StoredProposals(
            $em,
            StoredProposalWriterTest::writer(),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-25T10:31:07+00:00')
        );
    }

    public function testStoreWritesTheProposalWithTheItemModificationDateAndFlushes(): void
    {
        $item = StoredProposalWriterTest::entity([], new \DateTimeImmutable('2026-11-20T08:00:00+00:00'));
        $em = $this->entityManager($item);
        $payload = ['alignment' => ['lrmi:teaches' => [['id' => 9, 'title' => 'S']]], 'justifications' => []];

        $this->assertTrue($this->store($em)->store(7, $payload, 'claude-test'));

        $this->assertSame([['Omeka\Entity\Item', 7]], $em->asked);
        $this->assertSame(1, $em->flushes);
        $stored = StoredProposal::decode($item->getValues()->items[0]->getValue());
        $this->assertSame('2026-11-20T08:00:00+00:00', $stored['item_modified']);
        $this->assertSame('2026-11-25T10:31:07+00:00', $stored['created']);
        $this->assertSame('claude-test', $stored['model']);
        $this->assertSame(['lrmi:teaches' => [9]], $stored['alignment']);
    }

    public function testAMissingItemIsNeitherWrittenNorFlushed(): void
    {
        $em = $this->entityManager(null);
        $this->assertFalse($this->store($em)->store(7, ['alignment' => []], 'm'));
        $this->assertFalse($this->store($em)->remove(7));
        $this->assertSame(0, $em->flushes);
    }

    public function testRemoveFlushesOnlyWhenSomethingWasRemoved(): void
    {
        $item = StoredProposalWriterTest::entity([]);
        $em = $this->entityManager($item);
        $this->assertFalse($this->store($em)->remove(7));
        $this->assertSame(0, $em->flushes);

        $this->store($em)->store(7, ['alignment' => []], 'm');
        $this->assertTrue($this->store($em)->remove(7));
        $this->assertSame(2, $em->flushes);
        $this->assertSame([], $item->getValues()->items);
    }

    public function testMayUpdateAsksTheAclAboutTheEntity(): void
    {
        $item = StoredProposalWriterTest::entity([]);
        $acl = new class {
            public array $asked = [];
            public bool $allow = true;
            public function userIsAllowed($resource, $privilege): bool
            {
                $this->asked[] = [$resource, $privilege];
                return $this->allow;
            }
        };
        $this->assertTrue($this->store($this->entityManager($item))->mayUpdate(7, $acl));
        $this->assertSame([[$item, 'update']], $acl->asked);
        $acl->allow = false;
        $this->assertFalse($this->store($this->entityManager($item))->mayUpdate(7, $acl));
        $this->assertFalse($this->store($this->entityManager(null))->mayUpdate(7, $acl));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'StoredProposalWriterTest|StoredProposalsTest'`
Expected: FAIL with `Class "OERManager\Service\Ai\StoredProposalWriter" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Service/Ai/StoredProposalWriter.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\Workflow\StatusGuard;

/**
 * TASK-011: reads, replaces and removes the stored AI proposal on an `Item`
 * entity, touching only `curation:data` values that carry the marker. Works
 * on the entity, not the API: imported REA usually fail the template's
 * required fields, and a refused API update would lose the proposal. The
 * caller flushes. Entities are duck-typed so the host tests use doubles.
 */
class StoredProposalWriter
{
    /**
     * @param \Closure():?object $property the managed `curation:data` Property, or null when not installed
     * @param \Closure():object $newValue a new `Omeka\Entity\Value`
     */
    public function __construct(
        private readonly \Closure $property,
        private readonly \Closure $newValue
    ) {
    }

    /** @return array<string,mixed>|null the first readable proposal */
    public function read(object $entity): ?array
    {
        return StoredProposal::firstOf(array_map(
            static fn (object $value): string => (string) $value->getValue(),
            $this->marked($entity)
        ));
    }

    /** Any marker value, readable or not. */
    public function has(object $entity): bool
    {
        return [] !== $this->marked($entity);
    }

    /** @param array<string,mixed> $proposal */
    public function replace(object $entity, array $proposal): bool
    {
        $property = ($this->property)();
        if (null === $property) {
            return false;
        }
        $this->remove($entity);
        $value = ($this->newValue)();
        $value->setResource($entity);
        $value->setProperty($property);
        $value->setType('literal');
        $value->setValue(StoredProposal::encode($proposal));
        // A machine record, never shown on the public page (as ADR-0015 §2).
        $value->setIsPublic(false);
        $entity->getValues()->add($value);
        return true;
    }

    public function remove(object $entity): bool
    {
        $marked = $this->marked($entity);
        foreach ($marked as $value) {
            $entity->getValues()->removeElement($value);
        }
        return [] !== $marked;
    }

    /** @return list<object> */
    private function marked(object $entity): array
    {
        $marked = [];
        foreach ($entity->getValues() as $value) {
            if (
                StoredProposal::TERM === StatusGuard::term($value->getProperty())
                && StoredProposal::isProposal((string) $value->getValue())
            ) {
                $marked[] = $value;
            }
        }
        return $marked;
    }
}
```

`src/Service/Ai/StoredProposals.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

/**
 * TASK-011: the stored AI proposal by item id. Finds the `Item` entity, lets
 * StoredProposalWriter change it and flushes only when something changed.
 * A direct entity write does not move `o:modified` and skips template
 * validation (the TASK-076 resync pattern). Not final: the controllers' tests
 * stand it in.
 */
class StoredProposals
{
    public const ITEM = 'Omeka\Entity\Item';

    private \Closure $now;

    /** @param object $entityManager Doctrine EntityManager (duck-typed: find(), flush()) */
    public function __construct(
        private object $entityManager,
        private StoredProposalWriter $writer,
        ?\Closure $now = null
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /** @param array<string,mixed> $payload ProposeRunner::run() */
    public function store(int $itemId, array $payload, string $model): bool
    {
        $item = $this->entityManager->find(self::ITEM, $itemId);
        if (null === $item) {
            return false;
        }
        $proposal = StoredProposal::fromPayload($payload, $model, ($this->now)(), $item->getModified());
        if (!$this->writer->replace($item, $proposal)) {
            return false;
        }
        $this->entityManager->flush();
        return true;
    }

    public function remove(int $itemId): bool
    {
        $item = $this->entityManager->find(self::ITEM, $itemId);
        if (null === $item || !$this->writer->remove($item)) {
            return false;
        }
        $this->entityManager->flush();
        return true;
    }

    /** The native ACL on the entity: in a Job, the identity is the Job's owner. */
    public function mayUpdate(int $itemId, object $acl): bool
    {
        $item = $this->entityManager->find(self::ITEM, $itemId);
        return null !== $item && (bool) $acl->userIsAllowed($item, 'update');
    }
}
```

In `config/module.config.php`, in `service_manager.factories`, after `Service\Ai\ProposalStore::class`:

```php
            // TASK-011: the AI proposal stored in the REA (private curation:data).
            Service\Ai\StoredProposalWriter::class => function ($container) {
                $entityManager = $container->get('Omeka\EntityManager');
                $api = $container->get('Omeka\ApiManager');
                $propertyId = null;
                $resolved = false;
                return new Service\Ai\StoredProposalWriter(
                    function () use ($entityManager, $api, &$propertyId, &$resolved) {
                        if (!$resolved) {
                            $resolved = true;
                            $found = $api->search('properties', ['term' => Service\Ai\StoredProposal::TERM])->getContent();
                            $propertyId = $found ? (int) $found[0]->id() : null;
                        }
                        return null === $propertyId
                            ? null
                            : $entityManager->getReference(\Omeka\Entity\Property::class, $propertyId);
                    },
                    static fn () => new \Omeka\Entity\Value()
                );
            },
            Service\Ai\StoredProposals::class => function ($container) {
                return new Service\Ai\StoredProposals(
                    $container->get('Omeka\EntityManager'),
                    $container->get(Service\Ai\StoredProposalWriter::class)
                );
            },
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'StoredProposalWriterTest|StoredProposalsTest|ModuleConfigTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
make lint && make test
git add src/Service/Ai/StoredProposalWriter.php src/Service/Ai/StoredProposals.php config/module.config.php test/Service/Ai/StoredProposalWriterTest.php test/Service/Ai/StoredProposalsTest.php
git commit -m "feat: write the stored AI proposal directly on the REA entity (TASK-011)"
```

---

### Task 3: Who enters a batch, and one batch at a time

**Files:**
- Create: `src/Service/Ai/BatchProposeEligibility.php`
- Modify: `src/Service/Governance/BatchJobLookup.php` (add `isActive`), `config/module.config.php` (factory)
- Test: `test/Service/Ai/BatchProposeEligibilityTest.php`, `test/Service/Governance/BatchJobLookupTest.php`

**Interfaces:**
- Consumes: `StoredProposal::searchRow()` (Task 1); `IdConstraintResolver::resolve(array $query): ?array` (all queue ids for `['status' => 'queue']`); `Omeka\Api\Manager::search('items', $params, ['returnScalar' => 'id'])`.
- Produces:
  - `class BatchProposeEligibility` (not final), constructor `(ApiManager $api, IdConstraintResolver $resolver)`; `split(array $ids): array{enter:list<int>, out_of_queue:list<int>, has_proposal:list<int>}`; `stillEligible(int $id): bool`.
  - `BatchJobLookup::isActive(string $class): bool`.

- [ ] **Step 1: Write the failing tests**

`test/Service/Ai/BatchProposeEligibilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\IdConstraintResolver;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

final class BatchProposeEligibilityTest extends TestCase
{
    private array $searches = [];

    private function eligibility(array $queue, array $withProposal): BatchProposeEligibility
    {
        $resolver = $this->createMock(IdConstraintResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            fn (array $query) => ['status' => 'queue'] === $query ? $queue : null
        );
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnCallback(
            function (string $resource, array $params, array $options = []) use ($withProposal) {
                $this->searches[] = [$resource, $params, $options];
                return new Response(array_values(array_intersect($params['id'], $withProposal)));
            }
        );
        return new BatchProposeEligibility($api, $resolver);
    }

    public function testTheSelectionIsSplitInThreeBuckets(): void
    {
        $split = $this->eligibility([1, 2, 3, 9], [2])->split([1, 2, 3, 4, 5]);
        $this->assertSame(['enter' => [1, 3], 'out_of_queue' => [4, 5], 'has_proposal' => [2]], $split);
        $this->assertSame([[
            'items',
            ['id' => [1, 2, 3], 'property' => [['property' => 'curation:data', 'type' => 'sw', 'text' => 'OERManager/ai-proposal/1']]],
            ['returnScalar' => 'id'],
        ]], $this->searches);
    }

    public function testNothingInTheQueueAsksNoSecondSearch(): void
    {
        $split = $this->eligibility([], [])->split([4, 5]);
        $this->assertSame(['enter' => [], 'out_of_queue' => [4, 5], 'has_proposal' => []], $split);
        $this->assertSame([], $this->searches);
    }

    public function testAnEmptySelectionIsEmpty(): void
    {
        $this->assertSame(['enter' => [], 'out_of_queue' => [], 'has_proposal' => []], $this->eligibility([1], [])->split([]));
    }

    public function testStillEligible(): void
    {
        $eligibility = $this->eligibility([1, 2], [2]);
        $this->assertTrue($eligibility->stillEligible(1));
        $this->assertFalse($eligibility->stillEligible(2));
        $this->assertFalse($eligibility->stillEligible(3));
    }
}
```

Append to `test/Service/Governance/BatchJobLookupTest.php` (its `entityManager()` and `job()` helpers already filter by `class` and `status`, `in_array` for arrays):

```php
    public function testIsActiveSeesOnlyALiveJobOfTheClass(): void
    {
        $now = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-25T12:00:00+00:00');
        $lookup = fn (array $jobs) => new BatchJobLookup($this->entityManager(null, $jobs), $now);

        $this->assertFalse($lookup([])->isActive('Ai\Batch'));
        $this->assertFalse($lookup([$this->job('Ai\Batch', 3, 'completed', [], 1, '2026-11-25T11:30:00+00:00')])->isActive('Ai\Batch'));
        $this->assertFalse($lookup([$this->job('Other', 3, 'in_progress', [], 1, '2026-11-25T11:30:00+00:00')])->isActive('Ai\Batch'));
        $this->assertTrue($lookup([$this->job('Ai\Batch', 3, 'in_progress', [], 1, '2026-11-25T11:30:00+00:00')])->isActive('Ai\Batch'));
        $this->assertTrue($lookup([$this->job('Ai\Batch', 3, 'starting', [], 1, null)])->isActive('Ai\Batch'));
        // Killed hours ago: Omeka never marks it, so it must not block launching forever.
        $this->assertFalse($lookup([$this->job('Ai\Batch', 3, 'in_progress', [], 1, '2026-11-25T09:00:00+00:00')])->isActive('Ai\Batch'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'BatchProposeEligibilityTest|BatchJobLookupTest'`
Expected: FAIL (`BatchProposeEligibility` not found; `isActive` undefined).

- [ ] **Step 3: Write minimal implementation**

`src/Service/Ai/BatchProposeEligibility.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Ai;

use OERManager\Service\IdConstraintResolver;
use OERManager\Service\IdConstraints;
use Omeka\Api\Manager as ApiManager;

/**
 * TASK-011: which REA of a selection a batch proposes. Only REA in the queue
 * (IdConstraints::queueBranches) without a stored proposal enter; the rest is
 * skipped and counted. Id-only searches: no REA is read whole (NFR-004).
 */
class BatchProposeEligibility
{
    public function __construct(
        private ApiManager $api,
        private IdConstraintResolver $resolver
    ) {
    }

    /**
     * @param list<int> $ids
     * @return array{enter:list<int>, out_of_queue:list<int>, has_proposal:list<int>}
     */
    public function split(array $ids): array
    {
        $split = ['enter' => [], 'out_of_queue' => [], 'has_proposal' => []];
        if ([] === $ids) {
            return $split;
        }
        $queue = array_flip($this->resolver->resolve(['status' => IdConstraints::STATUS_QUEUE]) ?? []);
        $inQueue = [];
        foreach ($ids as $id) {
            if (isset($queue[$id])) {
                $inQueue[] = $id;
            } else {
                $split['out_of_queue'][] = $id;
            }
        }
        if ([] === $inQueue) {
            return $split;
        }
        $marked = array_flip(array_map('intval', (array) $this->api->search(
            'items',
            ['id' => $inQueue, 'property' => [StoredProposal::searchRow('sw')]],
            ['returnScalar' => 'id']
        )->getContent()));
        foreach ($inQueue as $id) {
            $split[isset($marked[$id]) ? 'has_proposal' : 'enter'][] = $id;
        }
        return $split;
    }

    public function stillEligible(int $id): bool
    {
        return [$id] === $this->split([$id])['enter'];
    }
}
```

In `src/Service/Governance/BatchJobLookup.php`, after `hasDied()`:

```php
    /**
     * True when a Job of `$class` is starting, running or stopping and has not
     * died (TASK-011: one AI batch at a time, so the same REA are never paid
     * for twice). A killed Job stays `in_progress` forever, so staleness lets
     * the next batch start.
     */
    public function isActive(string $class): bool
    {
        $jobs = $this->entityManager->getRepository('Omeka\Entity\Job')->findBy(
            ['class' => $class, 'status' => ['starting', 'in_progress', 'stopping']],
            ['id' => 'DESC'],
            20
        );
        foreach ($jobs as $job) {
            if (!$this->hasDied((string) $job->getStatus(), $job->getStarted())) {
                return true;
            }
        }
        return false;
    }
```

In `config/module.config.php`, after the `StoredProposals` factory:

```php
            Service\Ai\BatchProposeEligibility::class => function ($container) {
                return new Service\Ai\BatchProposeEligibility(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\IdConstraintResolver::class)
                );
            },
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'BatchProposeEligibilityTest|BatchJobLookupTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
make lint && make test
git add src/Service/Ai/BatchProposeEligibility.php src/Service/Governance/BatchJobLookup.php config/module.config.php test/Service/Ai/BatchProposeEligibilityTest.php test/Service/Governance/BatchJobLookupTest.php
git commit -m "feat: batch AI eligibility and one batch at a time (TASK-011)"
```

---

### Task 4: The batch Job

**Files:**
- Create: `src/Job/AiBatchProposeJob.php`
- Test: `test/Job/AiBatchProposeJobTest.php`

**Interfaces:**
- Consumes: `ProgressReporter` (`report(string $step, int $done, int $total): void`, `shouldStop(): bool`); `BatchProgressReporter(ProposalStore, callable $shouldStop, int $jobId, string $kind)` with `finish(string $status, array $tallies, string $batch)`; `LlmException`; `ProposeRunner::run(int): array`; `StoredProposals` (Task 2); `BatchProposeEligibility::stillEligible` (Task 3); `LlmSettings::MODEL`.
- Produces: `AiBatchProposeJob::KIND = 'ai-batch'`, `ABORT_AFTER = 5`; `public static function run(array $ids, callable $eligible, callable $propose, callable $store, ProgressReporter $progress, ?callable $log = null): array{done:int,total:int,proposed:int,skipped:int,failed:list<array{id:int,code:string}>,stopped:bool,aborted:bool}`. Failure codes: `llm`, `unexpected`, `not_stored`. Final state in `ProposalStore`: `{kind:'ai-batch', status:'completed'|'stopped'|'error', batch:'ai-batch-<jobId>', tallies}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Job;

use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;

final class AiBatchProposeJobTest extends TestCase
{
    private function progress(int $stopAt = PHP_INT_MAX): ProgressReporter
    {
        return new class ($stopAt) implements ProgressReporter {
            public array $reports = [];
            public function __construct(private int $stopAt)
            {
            }
            public function report(string $step, int $done, int $total): void
            {
                $this->reports[] = [$done, $total];
            }
            public function shouldStop(): bool
            {
                return end($this->reports)[0] >= $this->stopAt;
            }
        };
    }

    private function run(array $ids, callable $propose, ?callable $eligible = null, ?callable $store = null, ?ProgressReporter $progress = null, array &$stored = [], array &$logged = []): array
    {
        return AiBatchProposeJob::run(
            $ids,
            $eligible ?? static fn (int $id): bool => true,
            $propose,
            $store ?? static function (int $id, array $payload) use (&$stored): bool {
                $stored[] = $id;
                return true;
            },
            $progress ?? $this->progress(),
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );
    }

    public function testEveryEligibleReaIsProposedAndStored(): void
    {
        $stored = [];
        $progress = $this->progress();
        $tallies = $this->run([1, 2, 3], static fn (int $id): array => ['alignment' => []], static fn (int $id): bool => 2 !== $id, null, $progress, $stored);
        $this->assertSame(['done' => 3, 'total' => 3, 'proposed' => 2, 'skipped' => 1, 'failed' => [], 'stopped' => false, 'aborted' => false], $tallies);
        $this->assertSame([1, 3], $stored);
        $this->assertSame([0, 3], $progress->reports[0]);
        $this->assertSame([3, 3], end($progress->reports));
    }

    public function testAReaThatIsNoLongerEligibleIsSkippedWithoutCallingTheLlm(): void
    {
        $called = [];
        $this->run([1, 2], static function (int $id) use (&$called): array {
            $called[] = $id;
            return [];
        }, static fn (int $id): bool => 1 === $id);
        $this->assertSame([1], $called);
    }

    public function testAFailureOnOneReaDoesNotStopTheBatch(): void
    {
        $logged = [];
        $stored = [];
        $tallies = $this->run([1, 2, 3], static function (int $id): array {
            if (2 === $id) {
                throw new \RuntimeException('boom');
            }
            return [];
        }, null, null, null, $stored, $logged);
        $this->assertSame(2, $tallies['proposed']);
        $this->assertSame([['id' => 2, 'code' => 'unexpected']], $tallies['failed']);
        $this->assertSame(['OERManager ai batch item 2: boom'], $logged);
    }

    public function testAStoreThatWritesNothingIsAFailure(): void
    {
        $tallies = $this->run([1], static fn (): array => [], null, static fn (): bool => false);
        $this->assertSame([['id' => 1, 'code' => 'not_stored']], $tallies['failed']);
    }

    public function testFiveLlmErrorsInARowAbort(): void
    {
        $calls = 0;
        $tallies = $this->run(range(1, 8), static function () use (&$calls): array {
            $calls++;
            throw new LlmException('quota');
        });
        $this->assertTrue($tallies['aborted']);
        $this->assertSame(5, $calls);
        $this->assertSame(5, $tallies['done']);
        $this->assertSame('llm', $tallies['failed'][0]['code']);
    }

    public function testASuccessResetsTheStreak(): void
    {
        $tallies = $this->run(range(1, 9), static function (int $id): array {
            if (5 !== $id) {
                throw new LlmException('slow');
            }
            return [];
        });
        $this->assertFalse($tallies['aborted']);
        $this->assertSame(9, $tallies['done']);
        $this->assertSame(1, $tallies['proposed']);
    }

    public function testStopIsHonouredBetweenReas(): void
    {
        $tallies = $this->run([1, 2, 3, 4], static fn (): array => [], null, null, $this->progress(2));
        $this->assertTrue($tallies['stopped']);
        $this->assertSame(2, $tallies['done']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter AiBatchProposeJobTest`
Expected: FAIL with `Class "OERManager\Job\AiBatchProposeJob" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Job;

use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ProgressReporter;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Ai\ProposeRunner;
use OERManager\Service\Ai\StoredProposals;
use OERManager\Service\Governance\BatchProgressReporter;
use OERManager\Service\Llm\LlmException;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Job\AbstractJob;

/**
 * TASK-011: the batch AI proposal. For each REA still in the queue without a
 * proposal, and that the owner may update, runs the single proposal's
 * pipeline (ProposeRunner) and stores the result in the REA (StoredProposals).
 * It never applies a proposal (ADR-0007 addendum 1).
 *
 * The loop is `run()`, pure and host-tested; `perform()` only wires it. No
 * EntityManager::clear(): it would detach the authenticated owner (see
 * GovernanceBatchJob), and memory stays small per REA.
 */
class AiBatchProposeJob extends AbstractJob
{
    public const KIND = 'ai-batch';
    /** LLM errors in a row that abort: a bad key, quota or outage would fail every remaining REA. */
    public const ABORT_AFTER = 5;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var ProposeRunner $runner */
        $runner = $services->get(ProposeRunner::class);
        /** @var StoredProposals $proposals */
        $proposals = $services->get(StoredProposals::class);
        /** @var BatchProposeEligibility $eligibility */
        $eligibility = $services->get(BatchProposeEligibility::class);
        $acl = $services->get('Omeka\Acl');
        $logger = $services->get('Omeka\Logger');
        $model = (string) $services->get('Omeka\Settings')->get(LlmSettings::MODEL, '');

        $jobId = (int) $this->job->getId();
        $batch = 'ai-batch-' . $jobId;
        $ids = array_values(array_filter(
            array_map('intval', (array) $this->getArg('ids', [])),
            static fn (int $id): bool => $id > 0
        ));
        $progress = new BatchProgressReporter(
            $services->get(ProposalStore::class),
            fn (): bool => $this->shouldStop(),
            $jobId,
            self::KIND
        );
        try {
            $tallies = self::run(
                $ids,
                static fn (int $id): bool => $eligibility->stillEligible($id) && $proposals->mayUpdate($id, $acl),
                static fn (int $id): array => $runner->run($id),
                static fn (int $id, array $payload): bool => $proposals->store($id, $payload, $model),
                $progress,
                static function (string $message) use ($logger): void {
                    $logger->err($message);
                }
            );
            $status = $tallies['aborted'] ? 'error' : ($tallies['stopped'] ? 'stopped' : 'completed');
            $progress->finish($status, $tallies, $batch);
        } catch (\Throwable $e) {
            $logger->err('OERManager ai batch job ' . $jobId . ': ' . $e->getMessage());
            $progress->finish('error', ['code' => 'unexpected'], $batch);
        }
    }

    /**
     * @param list<int> $ids
     * @param callable(int):bool $eligible re-checked per REA: the queue may have moved since the preview
     * @param callable(int):array $propose ProposeRunner::run()
     * @param callable(int, array):bool $store
     * @param callable(string):void|null $log
     * @return array{done:int,total:int,proposed:int,skipped:int,failed:list<array{id:int,code:string}>,stopped:bool,aborted:bool}
     */
    public static function run(
        array $ids,
        callable $eligible,
        callable $propose,
        callable $store,
        ProgressReporter $progress,
        ?callable $log = null
    ): array {
        $total = count($ids);
        $tallies = ['done' => 0, 'total' => $total, 'proposed' => 0, 'skipped' => 0,
            'failed' => [], 'stopped' => false, 'aborted' => false];
        $streak = 0;
        $progress->report('batch', 0, $total);
        foreach ($ids as $id) {
            if ($progress->shouldStop()) {
                $tallies['stopped'] = true;
                break;
            }
            try {
                if (!$eligible($id)) {
                    $tallies['skipped']++;
                } elseif ($store($id, $propose($id))) {
                    $tallies['proposed']++;
                    $streak = 0;
                } else {
                    $tallies['failed'][] = ['id' => $id, 'code' => 'not_stored'];
                }
            } catch (LlmException $e) {
                $streak++;
                $tallies['failed'][] = ['id' => $id, 'code' => 'llm'];
                self::log($log, $id, $e);
            } catch (\Throwable $e) {
                $tallies['failed'][] = ['id' => $id, 'code' => 'unexpected'];
                self::log($log, $id, $e);
            }
            $tallies['done']++;
            $progress->report('batch', $tallies['done'], $total);
            if ($streak >= self::ABORT_AFTER) {
                $tallies['aborted'] = true;
                break;
            }
        }
        return $tallies;
    }

    private static function log(?callable $log, int $id, \Throwable $e): void
    {
        if (null !== $log) {
            $log('OERManager ai batch item ' . $id . ': ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter AiBatchProposeJobTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
make lint && make test
git add src/Job/AiBatchProposeJob.php test/Job/AiBatchProposeJobTest.php
git commit -m "feat: batch AI proposal Job that stores proposals in the REA (TASK-011)"
```

---

### Task 5: The batch controller, route and ACL

**Files:**
- Create: `src/Controller/Admin/AiBatchController.php`, `view/oer-manager/admin/ai-batch/form.phtml`
- Modify: `config/module.config.php` (controller factory, route), `src/Permissions/ReaAcl.php`
- Test: `test/Controller/AiBatchControllerTest.php`, `test/Permissions/ReaAclTest.php`

**Interfaces:**
- Consumes: `BatchSelection::resolveIds(array): list<int>`, `resolveMatching(array): list<int>` (both throw `BatchSelectionException` with `->reason`); `BatchProposeEligibility::split` (Task 3); `BatchJobLookup::find`, `hasDied`, `isActive` (Task 3); `ProposalStore::read/sweepOld`; `AiBatchProposeJob::KIND` (Task 4); `Omeka\Settings\Settings`; `LlmSettings::ENABLED`, `MODEL`.
- Produces: route `admin/oer-manager-ai-batch` (`/oer-manager/ai-batch[/:action]`), privileges `form`, `preview`, `launch`, `status`, `cancel`. JSON contracts:
  - `preview` → `{total, enter, outOfQueue, hasProposal, cost (float USD, 2 decimals), running (bool)}` or `{error}`.
  - `launch` → `{jobId, total}` or `{error: running|nothing|ai_disabled|dispatch|<selection reason>}`.
  - `status` → the stored state (`{kind:'ai-batch', status:'in_progress', done, total}`, or the final state with `tallies`), `{status:'error', code:'job_died'|'job_<native>'}`, or `{error: id|not_found|not_batch}`.
  - `cancel` → `{stopped: true}` or `{error}`.
  - Every POST action: `{error:'method'}` when not POST, `{error:'csrf'}` on a bad token.

- [ ] **Step 1: Write the failing tests**

`test/Controller/AiBatchControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Controller;

use OERManager\Controller\Admin\AiBatchController;
use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class AiBatchControllerTest extends TestCase
{
    private AiBatchController $controller;
    private $selection;
    private $eligibility;
    private $dispatcher;
    private ProposalStore $states;
    private Settings $settings;
    private $params;
    private $request;
    private string $dir;
    private bool $active = false;
    private ?array $job = null;
    private bool $died = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_ai_batch_' . bin2hex(random_bytes(6));
        $this->selection = $this->createMock(BatchSelection::class);
        $this->eligibility = $this->createMock(BatchProposeEligibility::class);
        $this->dispatcher = $this->createMock(Dispatcher::class);
        $this->states = new ProposalStore($this->dir);
        $this->settings = new Settings();
        $this->settings->set(LlmSettings::ENABLED, true);
        $this->settings->set(LlmSettings::MODEL, 'claude-test');
        $jobs = $this->createMock(BatchJobLookup::class);
        $jobs->method('isActive')->willReturnCallback(fn (string $class) => AiBatchProposeJob::class === $class && $this->active);
        $jobs->method('find')->willReturnCallback(fn (int $id) => $this->job);
        $jobs->method('hasDied')->willReturnCallback(fn () => $this->died);
        $this->controller = new AiBatchController(
            $this->selection,
            $this->eligibility,
            $this->dispatcher,
            $this->states,
            $this->createMock(\Laminas\Log\LoggerInterface::class),
            $jobs,
            $this->settings
        );
        $this->params = new class {
            public array $post = ['csrf' => 'valid'];
            public function fromPost($key = null, $default = null)
            {
                return null === $key ? $this->post : ($this->post[$key] ?? $default);
            }
            public function fromQuery($key = null, $default = null)
            {
                return $default;
            }
        };
        $this->request = new class {
            public bool $post = true;
            public function isPost()
            {
                return $this->post;
            }
        };
        $identity = new class {
            public function getId()
            {
                return 3;
            }
        };
        $this->controller->plugins = [
            'params' => $this->params, 'request' => $this->request, 'identity' => $identity,
            'url' => new class {
                public function fromRoute($route, $params = [])
                {
                    return $route . '/' . ($params['action'] ?? '');
                }
            },
        ];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function data(string $action): array
    {
        return $this->controller->{$action . 'Action'}()->getVariables();
    }

    private function selectionOf(array $ids, array $split): void
    {
        $this->params->post = ['csrf' => 'valid', 'scope' => 'ids', 'ids' => array_map('strval', $ids)];
        $this->selection->method('resolveIds')->willReturn($ids);
        $this->eligibility->method('split')->with($ids)->willReturn($split);
    }

    public function testEveryActionIsPostAndCsrfGuarded(): void
    {
        foreach (['preview', 'launch', 'status', 'cancel'] as $action) {
            $this->request->post = false;
            $this->assertSame('method', $this->data($action)['error'], $action);
            $this->request->post = true;
            $this->params->post = ['csrf' => 'bad'];
            $this->assertSame('csrf', $this->data($action)['error'], $action);
        }
    }

    public function testPreviewCountsTheBucketsAndTheCost(): void
    {
        $this->selectionOf([1, 2, 3, 4], ['enter' => [1, 2], 'out_of_queue' => [3], 'has_proposal' => [4]]);
        $this->assertSame(
            ['total' => 4, 'enter' => 2, 'outOfQueue' => 1, 'hasProposal' => 1, 'cost' => 0.01, 'running' => false],
            $this->data('preview')
        );
    }

    public function testPreviewOfTheMatchingFilter(): void
    {
        $this->params->post = ['csrf' => 'valid', 'scope' => 'matching', 'query' => 'status=queue&item_set_id=5'];
        $this->selection->expects($this->once())->method('resolveMatching')
            ->with(['status' => 'queue', 'item_set_id' => '5'])->willReturn([7]);
        $this->eligibility->method('split')->willReturn(['enter' => [7], 'out_of_queue' => [], 'has_proposal' => []]);
        $this->assertSame(1, $this->data('preview')['enter']);
    }

    public function testASelectionErrorIsAnswered(): void
    {
        $this->params->post = ['csrf' => 'valid', 'scope' => 'ids', 'ids' => []];
        $this->selection->method('resolveIds')->willThrowException(new BatchSelectionException('empty'));
        $this->assertSame('empty', $this->data('preview')['error']);
    }

    public function testLaunchDispatchesTheReaThatEnter(): void
    {
        $this->selectionOf([1, 2, 3], ['enter' => [1, 3], 'out_of_queue' => [2], 'has_proposal' => []]);
        $job = new \Omeka\Entity\Job();
        $this->dispatcher->expects($this->once())->method('dispatch')
            ->with(AiBatchProposeJob::class, ['ids' => [1, 3]])->willReturn($job);
        $this->assertSame(['jobId' => 1, 'total' => 2], $this->data('launch'));
    }

    public function testLaunchIsRefusedWhileABatchRuns(): void
    {
        $this->selectionOf([1], ['enter' => [1], 'out_of_queue' => [], 'has_proposal' => []]);
        $this->active = true;
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->assertSame('running', $this->data('launch')['error']);
        $this->assertTrue($this->data('preview')['running']);
    }

    public function testLaunchWithNothingToProposeOrWithoutAiDispatchesNothing(): void
    {
        $this->selectionOf([1], ['enter' => [], 'out_of_queue' => [1], 'has_proposal' => []]);
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->assertSame('nothing', $this->data('launch')['error']);
        $this->settings->set(LlmSettings::ENABLED, false);
        $this->assertSame('ai_disabled', $this->data('launch')['error']);
    }

    public function testStatusAndCancelServeOnlyTheOwnersAiBatch(): void
    {
        $this->params->post = ['csrf' => 'valid', 'jobId' => 0];
        $this->assertSame('id', $this->data('status')['error']);

        $this->params->post['jobId'] = 5;
        $this->assertSame('not_found', $this->data('status')['error']);

        $this->job = ['class' => AiBatchProposeJob::class, 'ownerId' => 99, 'status' => 'in_progress', 'args' => [], 'started' => null];
        $this->assertSame('not_found', $this->data('status')['error']);
        $this->assertSame('not_found', $this->data('cancel')['error']);

        $this->job['ownerId'] = 3;
        $this->job['class'] = 'OERManager\Job\GovernanceBatchJob';
        $this->assertSame('not_batch', $this->data('status')['error']);

        $this->job['class'] = AiBatchProposeJob::class;
        $this->assertSame(['status' => 'in_progress', 'done' => 0, 'total' => 0], $this->data('status'));
        $this->states->write(5, ['kind' => 'governance-batch', 'status' => 'in_progress']);
        $this->assertSame('not_batch', $this->data('status')['error']);
        $this->states->write(5, ['kind' => 'ai-batch', 'status' => 'in_progress', 'done' => 2, 'total' => 9]);
        $this->assertSame(2, $this->data('status')['done']);

        $this->died = true;
        $this->assertSame(['status' => 'error', 'code' => 'job_died'], $this->data('status'));
        $this->died = false;

        $this->dispatcher->expects($this->once())->method('stop')->with(5);
        $this->assertTrue($this->data('cancel')['stopped']);
    }

    public function testFormServesTheUrlsAndCsrf(): void
    {
        $view = $this->controller->formAction();
        $this->assertSame('oer-manager/admin/ai-batch/form', $view->getTemplate());
        $vars = $view->getVariables();
        $this->assertSame('admin/oer-manager-ai-batch/launch', $vars['urls']['launch']);
        $this->assertSame(AiBatchController::COST_PER_REA, $vars['costPerRea']);
        $this->assertNotSame('', $vars['csrf']);
    }
}
```

Check the stubs before running: `test/stubs/Laminas/View/Model/ViewModel.php` must offer `getTemplate()`, `setTemplate()`, `setTerminal()`; `test/stubs/Omeka/Settings/Settings.php` must offer `set()`/`get()`. Add a method to a stub only if it is missing, with the same signature as Laminas/Omeka.

In `test/Permissions/ReaAclTest.php`:
- add `use OERManager\Controller\Admin\AiBatchController;`;
- `CONTROLLERS` gains `AiBatchController::class`;
- `EXPECTED['rea_curador']` gains `AiBatchController::class => ['form', 'preview', 'launch', 'status', 'cancel']`.

Task 7 adds `'ai-proposal-discard'` to both `ReaAcl` and the test, once the action exists. Adding it now would fail the every-grant-is-an-action test.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'AiBatchControllerTest|ReaAclTest'`
Expected: FAIL (`AiBatchController` not found).

- [ ] **Step 3: Write minimal implementation**

`src/Controller/Admin/AiBatchController.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\Job\AiBatchProposeJob;
use OERManager\Service\Ai\BatchProposeEligibility;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Governance\BatchJobLookup;
use OERManager\Service\Governance\BatchSelection;
use OERManager\Service\Governance\BatchSelectionException;
use OERManager\Service\Llm\LlmSettings;
use Omeka\Job\Dispatcher;
use Omeka\Settings\Settings;

/**
 * TASK-011: the batch AI proposal, launched from the master view's selection
 * (ticked rows or everything matching the filter, as the governance batch).
 * Preview and launch only read and dispatch; AiBatchProposeJob writes. One
 * batch at a time. Status and cancel serve only the owner's own AI batch,
 * read from the Job entity (BatchJobLookup), as GovernanceBatchController.
 */
class AiBatchController extends AbstractActionController
{
    public const CSRF_NAME = 'oer_ai_batch';
    public const ROUTE = 'admin/oer-manager-ai-batch';
    /** USD per proposal measured in TASK-062: an estimate shown before launching. */
    public const COST_PER_REA = 0.0052;

    public function __construct(
        private BatchSelection $selection,
        private BatchProposeEligibility $eligibility,
        private Dispatcher $jobDispatcher,
        private ProposalStore $states,
        private LoggerInterface $logger,
        private BatchJobLookup $jobs,
        private Settings $settings
    ) {
    }

    private function csrfValidator(): Csrf
    {
        return new Csrf(['name' => self::CSRF_NAME, 'salt' => 'oermanager', 'timeout' => 3600]);
    }

    private function guard(): ?JsonModel
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        return null;
    }

    public function formAction()
    {
        $url = fn (string $action): string => $this->url()->fromRoute(self::ROUTE, ['action' => $action]);
        $view = new ViewModel([
            'csrf' => $this->csrfValidator()->getHash(),
            'urls' => [
                'preview' => $url('preview'),
                'launch' => $url('launch'),
                'status' => $url('status'),
                'cancel' => $url('cancel'),
                // Failed ids link to their item; `__ID__` is replaced client-side.
                'item' => $this->url()->fromRoute('admin/id', ['controller' => 'item', 'action' => 'show', 'id' => '__ID__']),
            ],
            'costPerRea' => self::COST_PER_REA,
        ]);
        $view->setTemplate('oer-manager/admin/ai-batch/form');
        $view->setTerminal(true);
        return $view;
    }

    public function previewAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        $split = $this->split();
        if ($split instanceof JsonModel) {
            return $split;
        }
        $enter = count($split['enter']);
        return new JsonModel([
            'total' => $enter + count($split['out_of_queue']) + count($split['has_proposal']),
            'enter' => $enter,
            'outOfQueue' => count($split['out_of_queue']),
            'hasProposal' => count($split['has_proposal']),
            'cost' => round($enter * self::COST_PER_REA, 2),
            'running' => $this->jobs->isActive(AiBatchProposeJob::class),
        ]);
    }

    public function launchAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        if (!$this->aiEnabled()) {
            return new JsonModel(['error' => 'ai_disabled']);
        }
        if ($this->jobs->isActive(AiBatchProposeJob::class)) {
            return new JsonModel(['error' => 'running']);
        }
        $split = $this->split();
        if ($split instanceof JsonModel) {
            return $split;
        }
        if ([] === $split['enter']) {
            return new JsonModel(['error' => 'nothing']);
        }
        $this->states->sweepOld(86400);
        try {
            $job = $this->jobDispatcher->dispatch(AiBatchProposeJob::class, ['ids' => $split['enter']]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager ai batch dispatch: ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }
        return new JsonModel(['jobId' => (int) $job->getId(), 'total' => count($split['enter'])]);
    }

    public function statusAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        [$job, $state, $refusal] = $this->batchJob();
        if ($refusal) {
            return $refusal;
        }
        $native = $job['status'];
        $finished = in_array($native, BatchJobLookup::FINISHED, true);
        if (!$finished && $this->jobs->hasDied($native, $job['started'])) {
            return new JsonModel(['status' => 'error', 'code' => 'job_died']);
        }
        if (null === $state) {
            return new JsonModel($finished
                ? ['status' => 'error', 'code' => 'job_' . $native]
                : ['status' => 'in_progress', 'done' => 0, 'total' => 0]);
        }
        if ($finished && 'in_progress' === ($state['status'] ?? '')) {
            return new JsonModel(['status' => 'error', 'code' => 'job_died']);
        }
        return new JsonModel($state);
    }

    public function cancelAction()
    {
        if ($refusal = $this->guard()) {
            return $refusal;
        }
        [$job, , $refusal] = $this->batchJob();
        if ($refusal) {
            return $refusal;
        }
        $this->jobDispatcher->stop($job['id']);
        return new JsonModel(['stopped' => true]);
    }

    /** @return array{enter:list<int>, out_of_queue:list<int>, has_proposal:list<int>}|JsonModel */
    private function split(): array|JsonModel
    {
        try {
            if ('matching' === $this->params()->fromPost('scope')) {
                parse_str((string) $this->params()->fromPost('query', ''), $query);
                $ids = $this->selection->resolveMatching($query);
            } else {
                $ids = $this->selection->resolveIds((array) $this->params()->fromPost('ids', []));
            }
            return $this->eligibility->split($ids);
        } catch (BatchSelectionException $e) {
            return new JsonModel(['error' => $e->reason]);
        } catch (\Throwable $e) {
            $this->logger->err('OERManager ai batch selection: ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }
    }

    /**
     * The posted Job, only if it is an AI batch owned by the current user.
     * Anyone else's is `not_found`, so its existence is not disclosed.
     *
     * @return array{0:?array, 1:?array, 2:?JsonModel}
     */
    private function batchJob(): array
    {
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return [null, null, new JsonModel(['error' => 'id'])];
        }
        $job = $this->jobs->find($jobId);
        $identity = $this->identity();
        if (null === $job || null === $identity || $job['ownerId'] !== (int) $identity->getId()) {
            return [null, null, new JsonModel(['error' => 'not_found'])];
        }
        if (AiBatchProposeJob::class !== $job['class']) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        $state = $this->states->read($jobId);
        if (null !== $state && AiBatchProposeJob::KIND !== ($state['kind'] ?? null)) {
            return [null, null, new JsonModel(['error' => 'not_batch'])];
        }
        return [['id' => $jobId] + $job, $state, null];
    }

    /** The same minimum as IndexController::aiEnabled(): AI on and a model configured. */
    private function aiEnabled(): bool
    {
        return (bool) $this->settings->get(LlmSettings::ENABLED)
            && '' !== trim((string) $this->settings->get(LlmSettings::MODEL));
    }
}
```

`view/oer-manager/admin/ai-batch/form.phtml`:

```php
<?php
/**
 * TASK-011: the batch AI proposal sidebar, served into `#oer-batch-sidebar`.
 * Everything happens in `asset/js/ui/aiBatch.js`; the markup only carries the
 * URLs, the CSRF and the empty boxes.
 *
 * @var \Laminas\View\Renderer\PhpRenderer $this
 * @var string $csrf
 * @var array<string,string> $urls
 * @var float $costPerRea
 */
$translate = $this->plugin('translate');
$escape = $this->plugin('escapeHtml');
?>
<div class="oer-ai-batch"
     data-csrf="<?php echo $escape($csrf); ?>"
     data-preview-url="<?php echo $escape($urls['preview']); ?>"
     data-launch-url="<?php echo $escape($urls['launch']); ?>"
     data-status-url="<?php echo $escape($urls['status']); ?>"
     data-cancel-url="<?php echo $escape($urls['cancel']); ?>"
     data-item-url="<?php echo $escape($urls['item']); ?>">
    <h3><?php echo $translate('Proponer con IA (lote)'); ?></h3>
    <p><?php echo $translate('La IA propone el anclaje de los REA en cola que aún no tienen propuesta. No cambia ningún REA: la propuesta queda guardada hasta que un curador la revise en el panel.'); ?></p>
    <button type="button" class="button oer-ai-batch-preview-button"><?php echo $translate('Calcular'); ?></button>
    <div class="oer-ai-batch-preview" hidden aria-live="polite"></div>
    <div class="oer-ai-batch-progress" hidden aria-live="polite"></div>
    <p class="oer-ai-batch-error error" hidden role="alert"></p>
</div>
```

In `config/module.config.php`, in `controllers.factories`:

```php
            // TASK-011: batch AI proposal.
            Controller\Admin\AiBatchController::class => function ($container) {
                return new Controller\Admin\AiBatchController(
                    $container->get(Service\Governance\BatchSelection::class),
                    $container->get(Service\Ai\BatchProposeEligibility::class),
                    $container->get('Omeka\Job\Dispatcher'),
                    $container->get(Service\Ai\ProposalStore::class),
                    $container->get('Omeka\Logger'),
                    $container->get(Service\Governance\BatchJobLookup::class),
                    $container->get('Omeka\Settings')
                );
            },
```

and, in `router.routes.admin.child_routes` after `oer-manager-batch`:

```php
                    'oer-manager-ai-batch' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/oer-manager/ai-batch[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'OERManager\Controller\Admin',
                                'controller' => Controller\Admin\AiBatchController::class,
                                'action' => 'form',
                            ],
                        ],
                        'may_terminate' => true,
                    ],
```

In `src/Permissions/ReaAcl.php`: `use OERManager\Controller\Admin\AiBatchController;` and in `GRANTS`:

```php
        // TASK-011 (ADR-0023 §1: «AI (single and batch)» is the curator's).
        AiBatchController::class => [
            self::CURATOR => ['form', 'preview', 'launch', 'status', 'cancel'],
        ],
```

Check how `ReaAcl::register()` adds ACL resources: if it iterates `GRANTS` keys, nothing else is needed; if it lists controllers explicitly, add `AiBatchController::class` there. Also check `Module::onBootstrap()` or the uninstall code for a hard-coded controller list.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'AiBatchControllerTest|ReaAclTest|ModuleConfigTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
make lint && make test
git add src/Controller/Admin/AiBatchController.php view/oer-manager/admin/ai-batch/form.phtml config/module.config.php src/Permissions/ReaAcl.php test/Controller/AiBatchControllerTest.php test/Permissions/ReaAclTest.php test/stubs
git commit -m "feat: curators preview and launch the batch AI proposal (TASK-011)"
```

---

### Task 6: «Propuesta IA» in the queue

**Files:**
- Modify: `src/Service/MasterViewQuery.php`, `Module.php` (`SEARCH_FILTER_LABELS`, `FILTER_VALUE_LABELS`, `addSearchFilters`), `view/oer-manager/admin/index/index.phtml` (quick bar select, row badge), `view/oer-manager/admin/index/search.phtml` (advanced field), `asset/css/oer-workflow-badge.css`
- Test: `test/Service/MasterViewQueryTest.php`, `test/ModuleRuntimeTest.php`

**Interfaces:**
- Consumes: `StoredProposal::searchRow`, `StoredProposal::anyMarked`, `StoredProposal::TERM` (Task 1).
- Produces: query parameter `ai_proposal=ready|none`; `MasterViewQuery::AI_PROPOSAL_FILTERS = ['ready' => 'sw', 'none' => 'nsw']`. `BatchSelection::resolveMatching` honours it for free, because it goes through `buildSearchParams`.

- [ ] **Step 1: Write the failing tests**

Append to `test/Service/MasterViewQueryTest.php`:

```php
    /** TASK-011: «Propuesta IA» lista / sin propuesta, on the stored marker. */
    public function testAiProposalFilter(): void
    {
        $query = new MasterViewQuery($this->apiResolvingClass());
        $row = static fn (string $type): array => ['property' => 'curation:data', 'type' => $type, 'text' => 'OERManager/ai-proposal/1'];
        $this->assertSame([$row('sw')], $query->buildSearchParams(['ai_proposal' => 'ready'])['property']);
        $this->assertSame([$row('nsw')], $query->buildSearchParams(['ai_proposal' => 'none'])['property']);
        $this->assertArrayNotHasKey('property', $query->buildSearchParams(['ai_proposal' => 'bogus']));
        $this->assertArrayNotHasKey('property', $query->buildSearchParams(['ai_proposal' => ['ready']]));
    }
```

Append to `test/ModuleRuntimeTest.php` a test with the same arrangement as the existing `addSearchFilters` tests (around line 113 and line 182): build the event the way they do, run `$this->module(['Omeka\ApiManager' => $this->createMock(Manager::class)])->addSearchFilters($event)`, and assert:
- `query = ['ai_proposal' => 'ready']` → `filters` is `['Propuesta IA' => ['Lista']]`;
- `query = ['ai_proposal' => 'bogus']` → `filters` is `[]`.

Name it `testAiProposalChipShowsOnlyKnownValues`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'MasterViewQueryTest|ModuleRuntimeTest'`
Expected: FAIL (no `property` row for `ai_proposal`; no chip).

- [ ] **Step 3: Write minimal implementation**

`src/Service/MasterViewQuery.php`: add `use OERManager\Service\Ai\StoredProposal;`, the constant after `AGE_DAYS`:

```php
    /** «Propuesta IA» (TASK-011): native search on the stored proposal's marker. */
    public const AI_PROPOSAL_FILTERS = ['ready' => 'sw', 'none' => 'nsw'];
```

and in `buildSearchParams()`, just before `$this->addAlignmentFilter(...)`:

```php
        $aiProposal = $query['ai_proposal'] ?? null;
        if (is_string($aiProposal) && isset(self::AI_PROPOSAL_FILTERS[$aiProposal])) {
            $params['property'][] = StoredProposal::searchRow(self::AI_PROPOSAL_FILTERS[$aiProposal]);
        }
```

`Module.php`:
- `SEARCH_FILTER_LABELS` gains `'ai_proposal' => 'Propuesta IA', // @translate`.
- `FILTER_VALUE_LABELS` gains `'ai_proposal' => ['ready' => 'Lista', 'none' => 'Sin propuesta'], // @translate`.
- In `addSearchFilters()`, inside the loop after the `if ('' === $value) { continue; }` check:

```php
            // TASK-011: an unknown value filters nothing, so it gets no chip.
            if ('ai_proposal' === $key && !isset(self::FILTER_VALUE_LABELS['ai_proposal'][$value])) {
                continue;
            }
```

`view/oer-manager/admin/index/index.phtml`, quick bar, right after the «Estado» `</select>`:

```php
        <?php
        $aiProposalOptions = ['ready' => $translate('Propuesta IA: lista'), 'none' => $translate('Propuesta IA: sin propuesta')];
        $currentAiProposal = is_scalar($query['ai_proposal'] ?? '') ? (string) ($query['ai_proposal'] ?? '') : '';
        ?>
        <label class="oer-visually-hidden" for="oer-filter-ai-proposal"><?php echo $translate('Propuesta IA'); ?></label>
        <select id="oer-filter-ai-proposal" name="ai_proposal">
            <option value=""><?php echo $translate('Propuesta IA: todas'); ?></option>
            <?php foreach ($aiProposalOptions as $value => $label): ?>
            <option value="<?php echo $escape($value); ?>" <?php echo $value === $currentAiProposal ? 'selected' : ''; ?>><?php echo $escape($label); ?></option>
            <?php endforeach; ?>
        </select>
```

In the same file, in the row's `<ul class="actions">`, after the workflow badge `<li>`:

```php
                        <?php
                        // TASK-011: the stored AI proposal, from the values the row already holds.
                        $proposalTexts = array_map(
                            static fn ($value): string => (string) $value->value(),
                            $item->value(\OERManager\Service\Ai\StoredProposal::TERM, ['all' => true, 'default' => []])
                        );
                        ?>
                        <?php if (\OERManager\Service\Ai\StoredProposal::anyMarked($proposalTexts)): ?>
                        <li><span class="oer-ai-badge" title="<?php echo $escape($translate('Tiene una propuesta de IA pendiente de revisar')); ?>"><?php echo $translate('IA'); ?></span></li>
                        <?php endif; ?>
```

`view/oer-manager/admin/index/search.phtml`, after the «Antigüedad» field:

```php
    <?php $currentAiProposal = is_scalar($query['ai_proposal'] ?? '') ? (string) ($query['ai_proposal'] ?? '') : ''; ?>
    <div class="field">
        <div class="field-meta">
            <label for="oer-search-ai-proposal"><?php echo $translate('Propuesta IA'); ?></label>
        </div>
        <div class="inputs">
            <select id="oer-search-ai-proposal" name="ai_proposal">
                <option value=""><?php echo $translate('Con o sin propuesta'); ?></option>
                <option value="ready" <?php echo 'ready' === $currentAiProposal ? 'selected' : ''; ?>><?php echo $translate('Lista'); ?></option>
                <option value="none" <?php echo 'none' === $currentAiProposal ? 'selected' : ''; ?>><?php echo $translate('Sin propuesta'); ?></option>
            </select>
        </div>
    </div>
```

`asset/css/oer-workflow-badge.css`, at the end. Text plus border, not colour alone (ADR-0014 rule 3):

```css
/* TASK-011: a REA with a stored AI proposal. */
.oer-ai-badge {
    display: inline-block;
    padding: 0 0.4em;
    border: 1px dashed currentColor;
    border-radius: 3px;
    font-size: 0.8em;
    font-weight: bold;
    line-height: 1.6;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'MasterViewQueryTest|ModuleRuntimeTest|BatchSelectionTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
make lint && make test
git add src/Service/MasterViewQuery.php Module.php view/oer-manager/admin/index/index.phtml view/oer-manager/admin/index/search.phtml asset/css/oer-workflow-badge.css test/Service/MasterViewQueryTest.php test/ModuleRuntimeTest.php
git commit -m "feat: filter the queue by a pending AI proposal and badge its rows (TASK-011)"
```

---

### Task 7: The proposal in the panel, its removal and «Descartar propuesta»

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`, `config/module.config.php` (one constructor argument), `src/Permissions/ReaAcl.php`, `view/oer-manager/admin/index/panel-config.phtml`, `view/oer-manager/admin/index/index.phtml`, `asset/js/config.js`
- Test: `test/Controller/IndexControllerTest.php`, `test/Permissions/ReaAclTest.php`

**Interfaces:**
- Consumes: `StoredProposals::remove(int): bool` (Task 2); `StoredProposal::firstOf`, `forPanel`, `TERM` (Task 1); `AiBatchController` (Task 5) for `canBatchAi`.
- Produces:
  - `recatalogPanel()` returns `['allowed', 'reason', 'current', 'aiProposal' => array|null]`. `aiProposal` is non-null only when the recatalog is allowed **and** the user may `ai-propose`; its shape is `StoredProposal::forPanel()`'s.
  - New action `ai-proposal-discard`, POST `{id, csrf}` with the re-cataloguer CSRF, answering `{removed: bool}` or `{error: method|csrf|denied|unexpected}`.
  - The view variable `canBatchAi` and the table attributes `data-can-batch-ai`, `data-ai-batch-form-url`, `data-ai-proposal-discard-url`; `readConfig()` exposes `canBatchAi`, `aiBatchFormUrl`, `aiProposalDiscardUrl`.

- [ ] **Step 1: Write the failing tests**

In `test/Controller/IndexControllerTest.php` the constructor is built by reflection, so the new `StoredProposals $storedProposals` parameter becomes a mock at `$this->dependencies['storedProposals']`. Add five tests:

1. **`testTheStoredProposalReachesOnlyUsersAllowedToProposeWithAi`.**
   - Give the drawer item a `curation:data` value whose text is `StoredProposal::encode(['created' => '2026-11-25T10:31:07+00:00', 'model' => 'm', 'item_modified' => null, 'alignment' => ['lrmi:teaches' => [7]], 'justifications' => []])`.
   - `drawerDetailsAction()` → `recatalog.aiProposal.created === '2026-11-25T10:31:07+00:00'`.
   - With the ACL mock denying `ai-propose` → `recatalog.aiProposal === null`.
2. **`testDiscardingTheProposalRemovesIt`.** POST `{id: 7, csrf: 'valid'}`; `storedProposals->remove(7)` is expected once and returns true; the response is `['removed' => true]`.
3. **`testDiscardingTheProposalIsPostCsrfAndRuleGuarded`.**
   - `remove` is never called.
   - Not POST → `error: method`; a bad CSRF → `error: csrf`.
   - A REA the recatalog rule refuses (the arrangement the existing recatalog-refusal tests use) → `error: denied`.
4. **`testApplyPublishAndDiscardRemoveTheProposalButRejectKeepsIt`.**
   - `remove` is expected with 7 exactly once for each successful `recatalogApplyAction()`, `publishProposalAction()` and `discardAction()`.
   - It is never called for `rejectProposalAction()`; assert with a separate mock expectation or by counting calls with a callback.
5. **`testARemovalFailureNeverBreaksTheAction`.** `remove` throws `RuntimeException`; a successful `publishProposalAction()` still answers `updated: true`.

For each, reuse the arrangement of the existing tests of `drawerDetailsAction`, `recatalogApplyAction`, `publishProposalAction`, `discardAction` and `rejectProposalAction` in this file: the item stub's values, the ACL callback, the `recatalogService->apply` return `['updated' => true]`, `applyWrites`, `canPublish`. Do not invent a new arrangement; extract a private helper when two tests share one.

In `test/Permissions/ReaAclTest.php`, `EXPECTED['rea_curador'][IndexController::class]` gains `'ai-proposal-discard'`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'IndexControllerTest|ReaAclTest'`
Expected: FAIL (`aiProposalDiscardAction` undefined; no `aiProposal` key).

- [ ] **Step 3: Write minimal implementation**

`src/Controller/Admin/IndexController.php`:
1. `use OERManager\Service\Ai\StoredProposal;` and `use OERManager\Service\Ai\StoredProposals;`.
2. A new last constructor parameter `StoredProposals $storedProposals` (property and assignment in the file's existing style).
3. In `indexAction()`, after `canBatchGovernance`:

```php
        // TASK-011: the batch AI proposal button, only where it can be used.
        $view->setVariable(
            'canBatchAi',
            $this->aiEnabled() && (bool) $this->acl->userIsAllowed(AiBatchController::class, 'preview')
        );
```

4. `recatalogPanel()` becomes:

```php
    private function recatalogPanel(ItemRepresentation $item, array $current): array
    {
        if (!$this->acl->userIsAllowed(self::class, 'recatalog-apply')) {
            return ['allowed' => false, 'reason' => '', 'current' => $current, 'aiProposal' => null];
        }
        $code = $this->recatalogDenial($item);
        return [
            'allowed' => null === $code,
            'reason' => RecatalogRule::message($code),
            'current' => $current,
            // TASK-011: only for who may propose with AI (curators), never a proposer.
            'aiProposal' => null === $code && $this->acl->userIsAllowed(self::class, 'ai-propose')
                ? $this->storedProposalView($item)
                : null,
        ];
    }

    /** The stored AI proposal of `$item` for the panel, titles resolved now; null when none is readable. */
    private function storedProposalView(ItemRepresentation $item): ?array
    {
        $proposal = StoredProposal::firstOf(array_map(
            static fn ($value): string => (string) $value->value(),
            $item->value(StoredProposal::TERM, ['all' => true, 'default' => []])
        ));
        if (null === $proposal) {
            return null;
        }
        return StoredProposal::forPanel($proposal, $item->modified(), function (int $id): ?string {
            try {
                return (string) $this->api()->read('items', $id)->getContent()->displayTitle();
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /** Removes the stored AI proposal after a successful action; never breaks it. */
    private function dropStoredProposal(int $id, array $result): array
    {
        if (!empty($result['updated'])) {
            try {
                $this->storedProposals->remove($id);
            } catch (\Throwable $e) {
                $this->logger->err('OERManager stored AI proposal removal item ' . $id . ': ' . $e->getMessage());
            }
        }
        return $result;
    }
```

5. `recatalogApplyAction()`: replace `return new JsonModel($result);` with `return new JsonModel($this->dropStoredProposal($id, $result));`.
6. `publishProposalAction()`: the closure passed to `workflowApply` becomes `fn () => $this->dropStoredProposal($id, $this->workflowService->publish($item, $this->contributor()))`.
7. `discardAction()`: the closure becomes `fn (ItemRepresentation $item, WorkflowReason $reason, string $who) => $this->dropStoredProposal((int) $item->id(), $this->workflowService->discard($item, $reason, $who))`. `rejectProposalAction()` is unchanged.
8. The new action:

```php
    /** «Descartar propuesta» (TASK-011): removes the stored AI proposal of one REA. */
    public function aiProposalDiscardAction()
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        $id = (int) $this->params()->fromPost('id');
        // Same rule as re-cataloguing: who may change the REA's anchoring may drop its proposal.
        if (null !== $this->recatalogRefusal($id)) {
            return new JsonModel(['error' => 'denied']);
        }
        try {
            return new JsonModel(['removed' => $this->storedProposals->remove($id)]);
        } catch (\Throwable $e) {
            $this->logger->err('OERManager stored AI proposal discard item ' . $id . ': ' . $e->getMessage());
            return new JsonModel(['error' => 'unexpected']);
        }
    }
```

`config/module.config.php`: append `$container->get(Service\Ai\StoredProposals::class)` as the last argument of the `IndexController` factory, with the comment `// TASK-011: the stored AI proposal (panel, removal hooks).`.

`src/Permissions/ReaAcl.php`: add `'ai-proposal-discard'` to the curator's `IndexController` grants, next to `'ai-propose'`.

`view/oer-manager/admin/index/panel-config.phtml`: document `@var bool $canBatchAi` and add:

```php
    data-can-batch-ai="<?php echo !empty($canBatchAi) ? '1' : '0'; ?>"
    data-ai-batch-form-url="<?php echo $escape($this->url('admin/oer-manager-ai-batch', ['action' => 'form'])); ?>"
    data-ai-proposal-discard-url="<?php echo $actionUrl('ai-proposal-discard'); ?>"
```

In `index.phtml`, pass `'canBatchAi' => !empty($canBatchAi)` to the `panel-config` partial. The partial is also rendered by `my-proposals.phtml`; there the variable is absent and evaluates to `0`.

`asset/js/config.js`, in the frozen object:

```js
        aiProposalDiscardUrl: d.aiProposalDiscardUrl,
        aiBatchFormUrl: d.aiBatchFormUrl,
        canBatchAi: flag(d.canBatchAi),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'IndexControllerTest|ReaAclTest|ModuleConfigTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
make lint && make test && make test-js
git add src/Controller/Admin/IndexController.php config/module.config.php src/Permissions/ReaAcl.php view/oer-manager/admin/index/panel-config.phtml view/oer-manager/admin/index/index.phtml asset/js/config.js test/Controller/IndexControllerTest.php test/Permissions/ReaAclTest.php
git commit -m "feat: the panel carries the stored AI proposal and actions remove it (TASK-011)"
```

---

### Task 8: The batch sidebar (JS)

**Files:**
- Create: `asset/js/core/aiBatchModel.js`, `asset/js/ui/aiBatch.js`, `test/js/aiBatchModel.test.js`
- Modify: `asset/js/ui/governanceBatch.js` (export `batchTarget()`; let the strip run for the AI batch too), `asset/js/main.js`, `view/oer-manager/admin/index/index.phtml` (button in `.oer-selection-bar`; strip copy)

**Interfaces:**
- Consumes: the Task 5 JSON contracts; `readConfig()` (`canBatchAi`, `totalResults`); `selectedIds()` from `ui/visibility.js`.
- Produces: `export function batchTarget(): {scope:'matching', query:string} | {scope:'ids', ids:string[]}` in `governanceBatch.js`. Pure functions in `core/aiBatchModel.js`: `previewModel(response)`, `formatCost(usd)`, `progressModel(state)`, `storedAiJob(raw)`.

- [ ] **Step 1: Write the failing test**

`test/js/aiBatchModel.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewModel, formatCost, progressModel, storedAiJob } from '../../asset/js/core/aiBatchModel.js';

test('formatCost writes the decimals with a comma', () => {
    assert.equal(formatCost(0), '0,00 $');
    assert.equal(formatCost(15.6), '15,60 $');
});

test('previewModel enables launching only when something enters and no batch runs', () => {
    const base = { total: 4, enter: 2, outOfQueue: 1, hasProposal: 1, cost: 0.01, running: false };
    assert.deepEqual(previewModel(base), {
        lines: [
            ['Entran', '2'],
            ['Se saltan: fuera de la cola', '1'],
            ['Se saltan: ya tienen propuesta', '1'],
            ['Coste estimado', '0,01 $']
        ],
        canLaunch: true,
        notice: ''
    });
    assert.equal(previewModel({ ...base, enter: 0 }).canLaunch, false);
    assert.equal(previewModel({ ...base, enter: 0 }).notice, 'Ningún REA de la selección necesita propuesta.');
    assert.equal(previewModel({ ...base, running: true }).canLaunch, false);
    assert.equal(previewModel({ ...base, running: true }).notice, 'Ya hay un lote de IA en marcha. Espera a que termine.');
});

test('progressModel tells polling from the end', () => {
    assert.deepEqual(progressModel({ status: 'in_progress', done: 3, total: 9 }), { action: 'poll', text: 'Procesados 3 de 9…' });
    const done = progressModel({ status: 'completed', tallies: { done: 9, total: 9, proposed: 7, skipped: 1, failed: [{ id: 4, code: 'llm' }], aborted: false } });
    assert.equal(done.action, 'done');
    assert.equal(done.text, 'Terminado: 7 con propuesta, 1 saltados, 1 con error.');
    assert.deepEqual(done.failed, [{ id: 4, code: 'llm' }]);
    assert.equal(progressModel({ status: 'stopped', tallies: { done: 2, total: 9, proposed: 2, skipped: 0, failed: [] } }).text, 'Cancelado: 2 con propuesta, 0 saltados, 0 con error.');
    assert.equal(progressModel({ status: 'error', tallies: { proposed: 0, skipped: 0, failed: [], aborted: true } }).text, 'Detenido tras varios errores seguidos de la IA: revisa la conexión con el proveedor. 0 con propuesta.');
    assert.equal(progressModel({ status: 'error', code: 'job_died' }).text, 'El proceso del lote se interrumpió. Revisa el registro de trabajos.');
});

test('storedAiJob reads only a positive job id', () => {
    assert.equal(storedAiJob('12'), 12);
    assert.equal(storedAiJob('0'), null);
    assert.equal(storedAiJob('x'), null);
    assert.equal(storedAiJob(null), null);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test test/js/aiBatchModel.test.js`
Expected: FAIL with `Cannot find module '.../asset/js/core/aiBatchModel.js'`.

- [ ] **Step 3: Write minimal implementation**

`asset/js/core/aiBatchModel.js`:

```js
/**
 * Batch AI proposal (TASK-011): pure decisions for ui/aiBatch.js. Texts are
 * Spanish msgids; whoever paints them passes them through Omeka.jsTranslate.
 */

export function formatCost(usd) {
    return Number(usd || 0).toFixed(2).replace('.', ',') + ' $';
}

export function previewModel(response) {
    const running = true === response.running;
    const enter = Number(response.enter || 0);
    let notice = '';
    if (running) {
        notice = 'Ya hay un lote de IA en marcha. Espera a que termine.';
    } else if (0 === enter) {
        notice = 'Ningún REA de la selección necesita propuesta.';
    }
    return {
        lines: [
            ['Entran', String(enter)],
            ['Se saltan: fuera de la cola', String(Number(response.outOfQueue || 0))],
            ['Se saltan: ya tienen propuesta', String(Number(response.hasProposal || 0))],
            ['Coste estimado', formatCost(response.cost)]
        ],
        canLaunch: !running && enter > 0,
        notice
    };
}

function counts(tallies) {
    const t = tallies || {};
    return Number(t.proposed || 0) + ' con propuesta, ' + Number(t.skipped || 0) + ' saltados, '
        + (t.failed || []).length + ' con error.';
}

export function progressModel(state) {
    if ('in_progress' === state.status) {
        return { action: 'poll', text: 'Procesados ' + Number(state.done || 0) + ' de ' + Number(state.total || 0) + '…' };
    }
    const tallies = state.tallies || {};
    const failed = tallies.failed || [];
    if ('completed' === state.status) {
        return { action: 'done', text: 'Terminado: ' + counts(tallies), failed };
    }
    if ('stopped' === state.status) {
        return { action: 'done', text: 'Cancelado: ' + counts(tallies), failed };
    }
    if (tallies.aborted) {
        return {
            action: 'done',
            text: 'Detenido tras varios errores seguidos de la IA: revisa la conexión con el proveedor. '
                + Number(tallies.proposed || 0) + ' con propuesta.',
            failed
        };
    }
    return { action: 'done', text: 'El proceso del lote se interrumpió. Revisa el registro de trabajos.', failed };
}

export function storedAiJob(raw) {
    const id = Number(raw);
    return Number.isInteger(id) && id > 0 ? id : null;
}
```

The progress and result sentences are composed with numbers, so they cannot be single msgids. The UI shows them untranslated, which is fine: the UI copy is Spanish.

`asset/js/ui/governanceBatch.js`:
- add after `currentQuery()`:

```js
/** What the selection bar targets now; shared with the batch AI proposal (TASK-011). */
export function batchTarget() {
    return 'matching' === selection.scope
        ? { scope: 'matching', query: currentQuery() }
        : { scope: 'ids', ids: selectedIds() };
}
```

- use `batchTarget()` in `mountForm()` instead of the inline ternary;
- in `initGovernanceBatch(config)`, the early return becomes `if (!config || (!config.canBatchGovernance && !config.canBatchAi)) { return; }`, so the «seleccionar los N que coinciden» strip also serves a curator who only has the AI batch. The sidebar mount stays as is: it looks for `.oer-batch-governance`, which the AI form does not contain.

`asset/js/ui/aiBatch.js`:

```js
import { previewModel, progressModel, storedAiJob } from '../core/aiBatchModel.js';
import { batchTarget } from './governanceBatch.js';

/**
 * Batch AI proposal (TASK-011): preview → launch → progress, served into
 * `#oer-batch-sidebar`, with reattach after a reload. Decisions live in
 * core/aiBatchModel.js.
 */

const JOB_KEY = 'oer-ai-batch-job';
const POLL_MS = 3000;
const ERROR_TEXT = {
    empty: 'La selección no contiene REA.',
    too_many: 'La selección supera el máximo permitido por lote; acota el filtro.',
    class_unresolved: 'No se encuentra la clase de REA en esta instalación.',
    computed_truncated: 'El filtro de integridad o de anclaje supera el tope de cálculo; combínalo con otro filtro.',
    running: 'Ya hay un lote de IA en marcha. Espera a que termine.',
    nothing: 'Ningún REA de la selección necesita propuesta.',
    ai_disabled: 'La IA no está configurada.',
    dispatch: 'No se pudo iniciar el lote.',
    csrf: 'La sesión ha caducado. Recarga la página.',
    unexpected: 'Error inesperado. Consulta el registro.'
};

const t = (text) => Omeka.jsTranslate(text);

function storage(action) {
    try {
        return action(window.localStorage);
    } catch (error) {
        return null;
    }
}

function post(url, pairs) {
    const body = new URLSearchParams();
    pairs.forEach(([name, value]) => body.append(name, value));
    return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then((response) => response.json())
        .catch(() => ({ error: 'unexpected' }));
}

function targetPairs(csrf) {
    const target = batchTarget();
    const pairs = [['csrf', csrf], ['scope', target.scope]];
    if ('matching' === target.scope) {
        pairs.push(['query', target.query]);
    } else {
        target.ids.forEach((id) => pairs.push(['ids[]', id]));
    }
    return pairs;
}

function showError(root, code) {
    const box = root.querySelector('.oer-ai-batch-error');
    box.textContent = t(ERROR_TEXT[code] || ERROR_TEXT.unexpected);
    box.hidden = false;
}

function renderProgress(root, jobId) {
    const box = root.querySelector('.oer-ai-batch-progress');
    box.hidden = false;
    root.querySelector('.oer-ai-batch-preview').hidden = true;
    root.querySelector('.oer-ai-batch-preview-button').hidden = true;
    box.textContent = '';
    const text = document.createElement('p');
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button';
    cancel.textContent = t('Cancelar');
    cancel.addEventListener('click', () => {
        cancel.disabled = true;
        post(root.dataset.cancelUrl, [['csrf', root.dataset.csrf], ['jobId', jobId]]);
    });
    box.append(text, cancel);

    const tick = () => {
        if (!root.isConnected) {
            return;
        }
        post(root.dataset.statusUrl, [['csrf', root.dataset.csrf], ['jobId', jobId]]).then((state) => {
            if (state.error) {
                storage((s) => s.removeItem(JOB_KEY));
                showError(root, state.error);
                return;
            }
            const model = progressModel(state);
            text.textContent = model.text;
            if ('poll' === model.action) {
                setTimeout(tick, POLL_MS);
                return;
            }
            storage((s) => s.removeItem(JOB_KEY));
            cancel.remove();
            if (model.failed.length) {
                const list = document.createElement('ul');
                model.failed.forEach((failure) => {
                    const li = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = root.dataset.itemUrl.replace('__ID__', String(failure.id));
                    link.textContent = '#' + failure.id;
                    li.append(link, document.createTextNode(' (' + failure.code + ')'));
                    list.append(li);
                });
                box.append(list);
            }
        });
    };
    tick();
}

function mount(root) {
    const pending = storedAiJob(storage((s) => s.getItem(JOB_KEY)));
    if (pending) {
        renderProgress(root, pending);
        return;
    }
    const previewBox = root.querySelector('.oer-ai-batch-preview');
    root.querySelector('.oer-ai-batch-preview-button').addEventListener('click', () => {
        root.querySelector('.oer-ai-batch-error').hidden = true;
        post(root.dataset.previewUrl, targetPairs(root.dataset.csrf)).then((response) => {
            if (response.error) {
                showError(root, response.error);
                return;
            }
            const model = previewModel(response);
            previewBox.textContent = '';
            const dl = document.createElement('dl');
            model.lines.forEach(([label, value]) => {
                const dt = document.createElement('dt');
                dt.textContent = t(label);
                const dd = document.createElement('dd');
                dd.textContent = value;
                dl.append(dt, dd);
            });
            previewBox.append(dl);
            if (model.notice) {
                const p = document.createElement('p');
                p.textContent = t(model.notice);
                previewBox.append(p);
            }
            const launch = document.createElement('button');
            launch.type = 'button';
            launch.className = 'button oer-button-primary';
            launch.textContent = t('Lanzar');
            launch.disabled = !model.canLaunch;
            launch.addEventListener('click', () => {
                launch.disabled = true;
                post(root.dataset.launchUrl, targetPairs(root.dataset.csrf)).then((launched) => {
                    if (launched.error) {
                        showError(root, launched.error);
                        return;
                    }
                    storage((s) => s.setItem(JOB_KEY, String(launched.jobId)));
                    renderProgress(root, launched.jobId);
                });
            });
            previewBox.append(launch);
            previewBox.hidden = false;
        });
    });
}

export function initAiBatch(config) {
    if (!config || !config.canBatchAi) {
        return;
    }
    const sidebar = document.getElementById('oer-batch-sidebar');
    if (sidebar) {
        $(sidebar).on('o:sidebar-content-loaded', () => {
            const root = sidebar.querySelector('.oer-ai-batch');
            if (root) {
                mount(root);
            }
        });
    }
    // Reattach after a reload, as the governance batch does; after admin.js binds its sidebar handler.
    if (storedAiJob(storage((s) => s.getItem(JOB_KEY)))) {
        $(() => {
            const opener = document.querySelector('.oer-ai-batch-open');
            if (opener) {
                opener.closest('.oer-selection-bar').hidden = false;
                opener.click();
            }
        });
    }
}
```

`asset/js/main.js`: `import { initAiBatch } from './ui/aiBatch.js';` and call `initAiBatch(config);` right after `initGovernanceBatch(config);`.

`view/oer-manager/admin/index/index.phtml`, in `.oer-selection-bar`, after the governance link:

```php
    <?php if (!empty($canBatchAi)): ?>
    <a href="#" class="button oer-ai-batch-open sidebar-content"
       data-sidebar-selector="#oer-batch-sidebar"
       data-sidebar-content-url="<?php echo $escape($this->url('admin/oer-manager-ai-batch', ['action' => 'form'])); ?>"><?php echo $translate('Proponer con IA (lote)…'); /* @translate */ ?></a>
    <?php endif; ?>
```

In the same file, the strip's `data-select-matching` and `data-matching-all` replace «para licencia y autoría» with «para las acciones en lote»:
- `'Seleccionar los %1$s que coinciden con el filtro para las acciones en lote'`;
- `'Los %1$s REA que coinciden con el filtro están seleccionados para las acciones en lote; la visibilidad sigue actuando solo sobre las filas marcadas.'`

If the strip only renders under `canBatchGovernance`, render it under `canBatchGovernance || canBatchAi`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `node --test test/js/aiBatchModel.test.js && make test-js`
Expected: PASS; the governance batch tests still pass. If a governance test pins the old strip copy, update its expected string to the new one.

- [ ] **Step 5: Commit**

```bash
make lint && make test && make test-js
git add asset/js/core/aiBatchModel.js asset/js/ui/aiBatch.js asset/js/ui/governanceBatch.js asset/js/main.js view/oer-manager/admin/index/index.phtml test/js
git commit -m "feat: batch AI proposal sidebar in the master view (TASK-011)"
```

---

### Task 9: Pre-fill the stored proposal in the panel (JS)

**Files:**
- Modify: `asset/js/core/anchorData.js`, `asset/js/ui/aiPropose.js`, `asset/js/ui/recatalog.js`
- Test: `test/js/anchorData.test.js`

**Interfaces:**
- Consumes: `recatalog.aiProposal` from Task 7 (`{created, changed, alignment:{term:[{id,title}]}, justifications}`); `config.aiProposalDiscardUrl`, `config.recatalogCsrf`.
- Produces: `parseRecatalog()` returns `aiProposal` (sanitised object or `null`); `export function proposalNotice(aiProposal, translate?): string` in `core/anchorData.js`; `export function prefillStoredProposal(config, $panel, $bar, itemId, aiProposal)` in `ui/aiPropose.js`.

- [ ] **Step 1: Write the failing test**

Append to `test/js/anchorData.test.js` (import `proposalNotice` next to `parseRecatalog`):

```js
test('parseRecatalog keeps a well-formed stored AI proposal and drops anything else', () => {
    const raw = (aiProposal) => JSON.stringify({ allowed: true, reason: '', current: {}, aiProposal });
    const good = {
        created: '2026-11-25T10:31:07+00:00',
        changed: true,
        alignment: { 'lrmi:teaches': [{ id: 7, title: 'Saber' }, { id: 'x', title: 'mal' }] },
        justifications: { 'lrmi:teaches': { 7: 'Porque sí' } }
    };
    assert.deepEqual(parseRecatalog(raw(good)).aiProposal, {
        created: '2026-11-25T10:31:07+00:00',
        changed: true,
        alignment: { 'lrmi:teaches': [{ id: 7, title: 'Saber' }] },
        justifications: { 'lrmi:teaches': { 7: 'Porque sí' } }
    });
    assert.equal(parseRecatalog(raw(null)).aiProposal, null);
    assert.equal(parseRecatalog(raw({ alignment: {} })).aiProposal, null);
    assert.equal(parseRecatalog(raw('x')).aiProposal, null);
    assert.equal(parseRecatalog('').aiProposal, null);
});

test('proposalNotice dates the proposal and warns when the REA changed', () => {
    assert.equal(proposalNotice({ created: '2026-11-25T10:31:07+00:00', changed: false }), 'Propuesta IA del 25/11/2026.');
    assert.equal(
        proposalNotice({ created: '2026-11-25T10:31:07+00:00', changed: true }),
        'Propuesta IA del 25/11/2026. El REA ha cambiado desde la propuesta.'
    );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `node --test test/js/anchorData.test.js`
Expected: FAIL (`aiProposal` undefined; `proposalNotice` not exported).

- [ ] **Step 3: Write minimal implementation**

`asset/js/core/anchorData.js`:
- `closed()` returns `aiProposal: null` too;
- `parseRecatalog` adds `aiProposal: parseAiProposal(data.aiProposal)` to its result;
- new functions:

```js
/** TASK-011: the stored AI proposal of the panel, or null when absent or malformed. */
function parseAiProposal(raw) {
    if (!raw || 'object' !== typeof raw || Array.isArray(raw) || 'string' !== typeof raw.created) {
        return null;
    }
    const alignment = {};
    const source = raw.alignment && 'object' === typeof raw.alignment ? raw.alignment : {};
    Object.keys(source).forEach((term) => {
        const chips = (Array.isArray(source[term]) ? source[term] : [])
            .filter((chip) => chip && Number.isInteger(chip.id) && chip.id > 0 && 'string' === typeof chip.title);
        if (chips.length) {
            alignment[term] = chips.map((chip) => ({ id: chip.id, title: chip.title }));
        }
    });
    const justifications = raw.justifications && 'object' === typeof raw.justifications && !Array.isArray(raw.justifications)
        ? raw.justifications
        : {};
    return { created: raw.created, changed: true === raw.changed, alignment, justifications };
}

/** «Propuesta IA del dd/mm/aaaa.», plus the warning when the REA changed since. */
export function proposalNotice(aiProposal, translate = (text) => text) {
    const [year, month, day] = aiProposal.created.slice(0, 10).split('-');
    let text = translate('Propuesta IA del %s.').replace('%s', `${day}/${month}/${year}`);
    if (aiProposal.changed) {
        text += ' ' + translate('El REA ha cambiado desde la propuesta.');
    }
    return text;
}
```

`asset/js/ui/aiPropose.js`:
- import `proposalNotice` from `../core/anchorData.js`;
- add `export` to the existing `function applyAiProposal`;
- add:

```js
/**
 * TASK-011: a proposal stored by the batch enters the editor as pending
 * chips when the panel opens, through the same path as «Proponer con IA»,
 * with a notice and «Descartar propuesta» in the anchor bar.
 */
export function prefillStoredProposal(config, $panel, $bar, itemId, aiProposal) {
    applyAiProposal({ config, $panel }, aiProposal.alignment, aiProposal.justifications);
    const $notice = $('<p>').addClass('oer-ai-stored').attr('role', 'status')
        .text(proposalNotice(aiProposal, (text) => Omeka.jsTranslate(text)));
    const $discard = $('<button>').attr('type', 'button').addClass('button oer-ai-stored-discard')
        .text(Omeka.jsTranslate('Descartar propuesta'));
    const failed = () => {
        $notice.text(Omeka.jsTranslate('No se pudo descartar la propuesta.'));
        $discard.prop('disabled', false);
    };
    $discard.on('click', () => {
        $discard.prop('disabled', true);
        $.post(config.aiProposalDiscardUrl, { id: itemId, csrf: config.recatalogCsrf })
            .done((r) => {
                // Reopen the panel: the editor is rebuilt without the proposal.
                const opener = document.querySelector(`.oer-open-drawer[data-item-id="${itemId}"]`);
                if (r && true === r.removed && opener) {
                    opener.click();
                    return;
                }
                failed();
            })
            .fail(failed);
    });
    $bar.prepend($('<div>').addClass('oer-ai-stored-box').append($notice, $discard));
}
```

Check that `applyAiProposal` only reads `ctx.config` and `ctx.$panel`; today it calls `proposeIntoEditor(ctx.config, ctx.$panel, …)` and `chipIdsByTerm(ctx.$panel)`. If it reads anything else from `ctx`, pass that too.

`asset/js/ui/recatalog.js`: import `prefillStoredProposal` from `./aiPropose.js`. In the `ANCHOR_SLOT` listener, replace the tail (from `$(slot).append(buildRecatalogPanel(...))` to `renderUndo(...)`) with:

```js
        const $panel = buildRecatalogPanel(config, itemId, recatalog);
        $(slot).append($panel);
        const $bar = $(bar);
        fillAnchorBar($bar, itemId);
        renderUndo(config, $bar, itemId);
        if (recatalog.aiProposal) {
            prefillStoredProposal(config, $panel, $bar, itemId, recatalog.aiProposal);
        }
```

If importing `aiPropose.js` from `recatalog.js` creates a cycle (check whether `aiPropose.js` imports `recatalog.js`), move `prefillStoredProposal` into a new `ui/storedProposal.js` that imports `applyAiProposal` from `aiPropose.js`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `node --test test/js/anchorData.test.js && make test-js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
make lint && make test-js
git add asset/js/core/anchorData.js asset/js/ui test/js/anchorData.test.js
git commit -m "feat: the panel pre-fills the stored AI proposal (TASK-011)"
```

---

### Task 10: Container check, with no LLM cost

**Files:**
- Create: `test/container/batch-ai-check.php`

**Interfaces:**
- Consumes: everything above. Test fakes from the host suite: find `FakeLlmClient` and `FakeClassifier` with `grep -rn "class FakeClassifier\|class FakeLlmClient" test` and `require` their files.

- [ ] **Step 1: Write the harness**

Behaviour, in this order; each check prints `OK <name>` or `FAIL <name>: <detail>`, and the script exits non-zero on any `FAIL`:

1. **Guards.** CLI only (`PHP_SAPI !== 'cli'` → 404, as the other harnesses), and it refuses to run without `--write`. Bootstrap Omeka as `propose-harness.php` does.
2. **Identity.** Take a `--curator=<login email>` argument and authenticate as that user: find the `Omeka\Entity\User` by email with the EntityManager and `$services->get('Omeka\AuthenticationService')->getStorage()->write($user)`. Refuse unless the user's role is `rea_curador`. Never print the email.
3. **Fixture.** As the `rea_curador`, create 4 REA with `api->create` (class `lrmi:LearningResource`, private, in the REA site if one is configured):
   - A, B: `curation:status` Propuesto;
   - C: Propuesto, created without any template, then given the REA template (setting `ReaTemplateFields` reads) with a direct entity write and flush, so it is template-invalid. Skip this step and print `SKIP template_invalid` when no REA template is configured;
   - D: `curation:status` Publicado and public.
   Record their ids for cleanup.
4. **`preview_buckets`.** `BatchProposeEligibility::split([A,B,C,D])` gives `enter` = A, B, C and `out_of_queue` = D.
5. **`job_stores_privately`.** Build a fake `ProposeRunner`:

   ```php
   new ProposeRunner($api, new AiCataloguer(
       new ContentExtractor(),
       new MediaVisionExtractor(new FakeLlmClient(), new PromptBuilder(), false),
       new ContextDistiller(new FakeLlmClient(['Resumen']), new PromptBuilder()),
       new FakeClassifier([...]),
       new FakeClassifier([])
   ), $services->get(MediaSourceInterface::class))
   ```

   - The first `FakeClassifier` proposes one existing curriculum term id in the shape its constructor takes (read `FakeClassifier`). Take the id from `api->search('items', ['resource_class_term' => 'cur:Saber', 'per_page' => 1])`, or the class `cur-fixture.php` uses.
   - Install it with `$services->setAllowOverride(true); $services->setService(ProposeRunner::class, $fake);`.
   - Persist an `Omeka\Entity\Job` (class `AiBatchProposeJob`, owner the curator, status `in_progress`, args `['ids' => [A,B,C]]`), then run `(new AiBatchProposeJob($job, $services))->perform()`.
   - Read the stored state: `ProposalStore::read($jobId)` has `status: completed` and `tallies.proposed === 3`.
   - A, B and C each have exactly one `curation:data` value that is private, starts with the marker and decodes.
   - Their `o:modified` is unchanged (read before and after, through a fresh `api->read`).
6. **`queue_filter`.** `api->search('items', ['id' => [A,B,C,D], 'property' => [StoredProposal::searchRow('sw')]])` returns 3 results; with `nsw` it returns 1.
7. **`skips_existing`.** `split([A])['has_proposal'] === [A]`.
8. **`one_at_a_time`.** With the persisted Job set to `in_progress` and `started` now, `BatchJobLookup::isActive(AiBatchProposeJob::class)` is true; set it to `completed` and it is false.
9. **`removal`.** `StoredProposals::remove(A)` returns true, and A no longer matches the `sw` search.
10. **Cleanup** (always, in `finally`): delete A, B, C, D and the Job entity; remove the Job's state file with the `ProposalStore` directory path used by the factory.

- [ ] **Step 2: Run it on the development stack**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/volume/modules/OERManager/test/container/batch-ai-check.php --write --curator="$CURATOR_EMAIL"` (set `CURATOR_EMAIL` in your shell, never in the script).
Expected: `OK preview_buckets`, `OK job_stores_privately`, `OK queue_filter`, `OK skips_existing`, `OK one_at_a_time`, `OK removal`; exit 0.

- [ ] **Step 3: Manual check in the browser, as a `rea_curador` who is not an administrator**

1. Master view, «En cola», tick two REA → «Proponer con IA (lote)…» → «Calcular» shows the buckets and the cost. Do not press «Lanzar» unless the owner has approved the LLM spend.
2. Give one REA a proposal by running the harness with its cleanup commented out locally, or by pasting an encoded proposal into a private `curation:data` value in the native form. Then check:
   - «Propuesta IA: lista» lists it with the «IA» badge;
   - opening it shows the notice and the chips pre-filled in «Re-catalogar»;
   - «Descartar propuesta» reopens the panel without it.
3. As a `rea_proponente`, open your own REA holding a proposal: no notice, no chips.

- [ ] **Step 4: Commit**

```bash
git add test/container/batch-ai-check.php
git commit -m "test: container check of the batch AI proposal without LLM cost (TASK-011)"
```

---

### Task 11: Governance documents

**Files:**
- Modify: `docs/decisions/0007-catalogacion-ia-assistida.md`, `docs/superpowers/specs/2026-11-25-task-011-batch-ai-proposal-design.md`, `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md`, `docs/requirements.md`

- [ ] **Step 1: ADR-0007 addendum 1 (2026-11-25, TASK-011).** It states:
  - a batch Job stores one private `curation:data` value per REA, with the marker `OERManager/ai-proposal/1` and versioned JSON (ids, justifications, model, dates, no debug);
  - it never applies the proposal; the curator confirms every change through the re-cataloguer (RF-009 unchanged);
  - the value is removed on apply, publish, discard and «Descartar propuesta», and kept on reject;
  - the direct entity write skips template validation and does not move `o:modified`.
- [ ] **Step 2: Spec.** Apply the four rulings of this plan:
  - drop «Clear the entity manager every 50 REA» and give the reason;
  - the container line becomes «a fake `ProposeRunner` injected into the real `perform()`»;
  - the Components rows for `ItemPanelData` and «Removal hooks» become `IndexController::recatalogPanel()` and the `IndexController` actions;
  - add «sent only to users allowed `ai-propose`».
- [ ] **Step 3: Backlog, traceability, project memory, requirements.**
  - **Backlog:** TASK-011 → «hecha», with a summary listing the components, the final test counts, the harness output and the pending real-LLM batch (TASK-068 (a) and the owner's budget). The TASK-070 row's «AI proposal ready» note points to TASK-011.
  - **Traceability:** the TASK-011 row maps RF-011, RF-009, NFR-002, NFR-004, NFR-010, NFR-011, ADR-0007 and ADR-0023.
  - **Project memory:** a dated entry.
  - **Requirements:** RF-011 → aceptado, implemented by TASK-011.
  Follow each document's existing style.
- [ ] **Step 4: Full verification**

Run: `make lint && make test && make test-js`
Expected: all green; coverage of `src/` ≥ 90 %.

- [ ] **Step 5: Commit**

```bash
git add docs
git commit -m "docs: close TASK-011 with ADR-0007 addendum 1"
```
