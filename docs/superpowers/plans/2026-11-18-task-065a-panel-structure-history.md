# TASK-065 part A: panel structure, style and history — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the admin detail panel readable: visible and collapsible sections, an aligned
re-cataloguing bar, and a curation history that is a paged, compact timeline with names instead of ids.

**Architecture:** The server-side history model (`CurationHistory`, pure PHP) learns to render
governance (typed, payload v2) events and stored labels. New re-cataloguing events store the label of
each value they touch. The client history model (`core/historyModel.js`, pure JS) adds the per-event
summary, the retired-term text and paging. `ui/drawerDetails.js` renders the timeline. The partial and
the CSS give each section a rule, a heading and, for Media, Record and History, a disclosure.

**Tech Stack:** PHP 8.4 (Omeka S 4.2 module, PHPUnit), plain ES modules (`ui/drawerDetails.js` uses
the DOM API, no jQuery), `node --test`, CSS with the existing `--oer-*` tokens.

**Spec:** `docs/superpowers/specs/2026-11-18-task-065-panel-usability-design.md` (part A, A1-A5)

## Global Constraints

- Within ADR-0014: native typefaces, palette and admin buttons; only existing `--oer-*` tokens.
- The panel stays in the native sidebar (ADR-0017).
- The RDF does not change shape; undo keeps working on ids only. Labels are for display.
- Accessibility at the admin's level (NFR-006): native `<details>`/`<summary>` and `<button>`.
- Every `Omeka.jsTranslate()` string is listed in `config/module.config.php` → `js_translate_strings`
  with `// @translate` (NFR-005). Every PHP UI string uses `$translate()` or `// @translate`.
- Container harnesses are CLI-only and start with the `PHP_SAPI !== 'cli'` guard.
- Commits: plain-text model line in the body, no `Co-Authored-By` trailer (owner's rule).
- Before finishing: `make lint`, `make test`, `make test-js`.

## Review Focus

1. **A governance event in the history** (licence or author changed) must show the URI/label or the
   literal, never `#1`. Pinned in Task 1.
2. **An old v1 event without `labels` whose target was deleted** must show «término retirado (#123)»,
   not a blank or a bare `#123`. Pinned in Tasks 2 and 3.
3. **A mixed history (re-cataloguing, governance, undo)** must keep newest-first order and the undo
   mark. Pinned in Task 1 (`testTheOrderGivenIsPreserved` stays, `testATypedUndoIsMarkedAsSuch`) and
   Task 4 (undo label in the line).
4. **Exactly 5, 6 and 0 events**: no «Ver 5 más» at 5, one press shows the 6th, 0 shows the coverage
   notice. Pinned in Task 3 (`nextShown`) and Task 4 (button hidden when all are shown).
5. **A user who cannot read a term** (isolated sites) gets the stored label when there is one, so a
   proposer sees names too. Pinned in Task 2 (stored label wins when the live read fails).

---

### Task 1: The history renders governance events as text

Today `CurationHistory::rows()` casts every value with `intval`. A governance event (payload `v: 2`)
carries typed values such as `['type' => 'uri', 'uri' => 'https://…']` or
`['type' => 'literal', 'value' => 'Ana']`; `intval` of a non-empty array is `1`, so every governance
change is painted as `#1`. `referencedIds()` has the same defect and asks the API for item 1.

**Files:**
- Modify: `src/Service/Governance/CurationHistory.php`
- Test: `test/Service/Governance/CurationHistoryTest.php`

**Interfaces:**
- Consumes: `OERManager\Service\CurationEvent::isTyped(array $payload): bool`,
  `OERManager\Service\Governance\ValueText::of(?string $label, ?string $uri): string`
- Produces: `CurationHistory::rows()` keeps its return shape; for typed events `added` is
  `list<string>` of value texts and `removed` is `list<array{title:string,reason:string}>` with
  `reason` always `''`. `referencedIds()` ignores typed events.

- [ ] **Step 1: Write the failing tests**

Add to `CurationHistoryTest`:

```php
    private function typedEvent(array $terms, string $op = 'governance'): array
    {
        return [
            'when' => '2026-11-18T10:00:00+00:00',
            'contributor' => 'curadora',
            'summary' => 'Gobernanza · dcterms:license +1 −1',
            'payload' => ['v' => 2, 'op' => $op, 'undoOf' => null, 'terms' => $terms],
        ];
    }

    /** Review Focus 1: a typed value is text, never `#1`. */
    public function testAGovernanceEventShowsItsValuesAsText(): void
    {
        $rows = CurationHistory::rows([$this->typedEvent([
            'dcterms:license' => [
                'before' => [['type' => 'uri', 'uri' => 'https://creativecommons.org/licenses/by/4.0/']],
                'after' => [['type' => 'uri', 'uri' => 'https://creativecommons.org/licenses/by-sa/4.0/']],
            ],
            'dcterms:creator' => [
                'before' => [],
                'after' => [['type' => 'literal', 'value' => 'Ana Pérez']],
            ],
        ])], []);

        $this->assertSame(['dcterms:license', 'dcterms:creator'], array_column($rows[0]['changes'], 'term'));
        $this->assertSame(['https://creativecommons.org/licenses/by-sa/4.0/'], $rows[0]['changes'][0]['added']);
        $this->assertSame(
            [['title' => 'https://creativecommons.org/licenses/by/4.0/', 'reason' => '']],
            $rows[0]['changes'][0]['removed']
        );
        $this->assertSame(['Ana Pérez'], $rows[0]['changes'][1]['added']);
        $this->assertFalse($rows[0]['changes'][1]['emptied']);
    }

    public function testATypedValueWithALabelShowsTheLabel(): void
    {
        $rows = CurationHistory::rows([$this->typedEvent([
            'dcterms:license' => [
                'before' => [],
                'after' => [['type' => 'uri', 'uri' => 'https://x.test/l', 'label' => 'CC BY 4.0']],
            ],
        ])], []);

        $this->assertSame(['CC BY 4.0'], $rows[0]['changes'][0]['added']);
    }

    /** Reordering authors is a change in v2 (ADR-0020), but nothing was added or removed. */
    public function testATypedEntryWithTheSameValuesShowsNoAddedOrRemoved(): void
    {
        $same = [['type' => 'literal', 'value' => 'A'], ['type' => 'literal', 'value' => 'B']];
        $rows = CurationHistory::rows([$this->typedEvent([
            'dcterms:creator' => ['before' => $same, 'after' => array_reverse($same)],
        ])], []);

        $this->assertSame([], $rows[0]['changes']);
    }

    public function testATypedUndoIsMarkedAsSuch(): void
    {
        $rows = CurationHistory::rows([$this->typedEvent([
            'dcterms:source' => ['before' => [['type' => 'literal', 'value' => 'X']], 'after' => []],
        ], 'undo')], []);

        $this->assertTrue($rows[0]['isUndo']);
        $this->assertTrue($rows[0]['changes'][0]['emptied']);
    }

    public function testReferencedIdsIgnoresTypedEvents(): void
    {
        $ids = CurationHistory::referencedIds([
            $this->typedEvent(['dcterms:creator' => ['before' => [], 'after' => [['type' => 'literal', 'value' => 'A']]]]),
            $this->event(['lrmi:teaches' => ['before' => [5], 'after' => [6]]]),
        ]);

        sort($ids);
        $this->assertSame([5, 6], $ids);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CurationHistoryTest`
Expected: FAIL — governance values come out as `#1`, and `referencedIds` returns `[1, 5, 6]`.

- [ ] **Step 3: Implement**

In `CurationHistory.php`, add `use OERManager\Service\CurationEvent;` and change `rows()` so each
entry is split by payload type. Replace the whole `foreach ($payload['terms'] ...)` loop with:

```php
            foreach ($payload['terms'] ?? [] as $term => $entry) {
                $change = CurationEvent::isTyped($payload)
                    ? self::typedChange((string) $term, $entry)
                    : self::linkChange((string) $term, $entry, $titles);
                if (null !== $change) {
                    $changes[] = $change;
                }
            }
```

Move the existing id logic, unchanged, into:

```php
    /**
     * @param array<string,mixed> $entry
     * @param array<int,string> $titles
     * @return array{term:string,added:list<string>,removed:list<array{title:string,reason:string}>,emptied:bool}|null
     */
    private static function linkChange(string $term, array $entry, array $titles): ?array
    {
        $before = array_map('intval', $entry['before'] ?? []);
        $after = array_map('intval', $entry['after'] ?? []);
        $addedIds = array_values(array_diff($after, $before));
        $removedIds = array_values(array_diff($before, $after));
        if ([] === $addedIds && [] === $removedIds) {
            return null;
        }

        $why = [];
        foreach ($entry['why'] ?? [] as $id => $reason) {
            $why[(int) $id] = (string) $reason;
        }

        $removed = [];
        foreach ($removedIds as $id) {
            $removed[] = ['title' => self::title($id, $titles), 'reason' => $why[$id] ?? ''];
        }

        return [
            'term' => $term,
            'added' => array_map(static fn (int $id): string => self::title($id, $titles), $addedIds),
            'removed' => $removed,
            'emptied' => [] === $after && [] !== $before,
        ];
    }
```

Add the typed branch:

```php
    /**
     * Governance values (ADR-0020) are not links: they are compared by their
     * canonical form and shown as their label, URI or literal.
     *
     * @param array<string,mixed> $entry
     * @return array{term:string,added:list<string>,removed:list<array{title:string,reason:string}>,emptied:bool}|null
     */
    private static function typedChange(string $term, array $entry): ?array
    {
        $before = self::keyed($entry['before'] ?? []);
        $after = self::keyed($entry['after'] ?? []);
        $added = array_values(array_diff_key($after, $before));
        $removed = array_values(array_diff_key($before, $after));
        if ([] === $added && [] === $removed) {
            return null;
        }
        return [
            'term' => $term,
            'added' => $added,
            'removed' => array_map(static fn (string $text): array => ['title' => $text, 'reason' => ''], $removed),
            'emptied' => [] === $after && [] !== $before,
        ];
    }

    /**
     * Canonical key → display text of each typed value.
     *
     * @param list<mixed> $values
     * @return array<string,string>
     */
    private static function keyed(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            ksort($value);
            $text = 'uri' === ($value['type'] ?? '')
                ? ValueText::of($value['label'] ?? null, $value['uri'] ?? null)
                : trim((string) ($value['value'] ?? ''));
            $out[(string) json_encode($value)] = $text;
        }
        return $out;
    }
```

In `referencedIds()`, skip typed events at the top of the loop:

```php
        foreach ($events as $event) {
            if (CurationEvent::isTyped($event['payload'])) {
                continue;
            }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CurationHistoryTest`
Expected: PASS (all old and new tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/CurationHistory.php test/Service/Governance/CurationHistoryTest.php
git commit -m "fix: the curation history shows governance values as text, not #1 (TASK-065)

Model: Claude Opus 5.5"
```

---

### Task 2: Re-cataloguing events store labels; the history uses them

A re-cataloguing event stores ids only. When a target can no longer be read (deleted when the
curriculum moved to `cur:`, or not readable by this user), the history shows `#<id>`. New events
store an optional `labels` map per term (id → qualified title). Decision taken while planning: this is
an optional key inside payload v1, not a new version, because `CurationEvent::decode()` already
ignores unknown keys and undo reads only `before`/`after`/`why`. The spec is updated in Task 6.

**Files:**
- Modify: `src/Service/CurationEvent.php` (`build()`)
- Modify: `src/Service/RecatalogService.php` (`apply()`)
- Modify: `src/Service/Governance/CurationHistory.php` (`linkChange()`, `title()`)
- Test: `test/Service/CurationEventTest.php`, `test/Service/RecatalogServiceTest.php`,
  `test/Service/Governance/CurationHistoryTest.php`

**Interfaces:**
- Consumes: `RecatalogService::currentTargets()` (its `titles` key),
  `RecatalogService::titlesFor(list<int> $ids, array<int,string> $dimensionOf): array<int,string>`
- Produces: `CurationEvent::build()` accepts `labels?: array<int,string>` per dimension and writes
  `terms[term].labels` (only ids in `before ∪ after`, non-empty). `CurationHistory` title order: live
  title → stored label → `#<id>`.

- [ ] **Step 1: Write the failing tests**

`test/Service/CurationEventTest.php`:

```php
    public function testLabelsOfTheTouchedValuesTravelInTheEvent(): void
    {
        $payload = CurationEvent::build([
            'lrmi:teaches' => [
                'before' => [1],
                'after' => [2],
                'labels' => [1 => 'Uno (1º ESO)', 2 => 'Dos (1º ESO)', 9 => 'Ajeno', 3 => '  '],
            ],
        ]);

        $this->assertSame([1 => 'Uno (1º ESO)', 2 => 'Dos (1º ESO)'], $payload['terms']['lrmi:teaches']['labels']);
        $this->assertSame(1, $payload['v']);
    }

    public function testAnEventWithoutLabelsHasNoLabelsKey(): void
    {
        $payload = CurationEvent::build(['lrmi:teaches' => ['before' => [], 'after' => [2]]]);

        $this->assertArrayNotHasKey('labels', $payload['terms']['lrmi:teaches']);
    }
```

`test/Service/RecatalogServiceTest.php` (the fixture's items 2 and 3 are the knowledge items
«Target» and «Other», with no `cur:materiaCurso`, so their qualified title is the bare label):

```php
    public function testTheEventStoresTheLabelsOfAddedAndRemovedValues(): void
    {
        $this->items[1] = $this->item(1, '', ['lrmi:teaches' => [$this->value('', $this->items[3], 'resource:item')]]);

        $this->service->apply(1, ['lrmi:teaches' => [2]], 'Curator');

        $event = $this->writes[0]['dcterms:provenance'][0];
        $payload = CurationEvent::decode($event['@annotation']['dcterms:replaces'][0]['@value']);
        $this->assertSame([3 => 'Other', 2 => 'Target'], $payload['terms']['lrmi:teaches']['labels']);
    }
```

`test/Service/Governance/CurationHistoryTest.php`:

```php
    /** Review Focus 5: an unreadable target keeps the name the event stored. */
    public function testAStoredLabelIsUsedWhenTheTargetCannotBeRead(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => [
                'before' => [], 'after' => [404], 'labels' => [404 => 'Fracciones (2º ESO)'],
            ]])],
            []
        );

        $this->assertSame(['Fracciones (2º ESO)'], $rows[0]['changes'][0]['added']);
    }

    public function testALiveTitleWinsOverTheStoredLabel(): void
    {
        $rows = CurationHistory::rows(
            [$this->event(['lrmi:teaches' => ['before' => [], 'after' => [7], 'labels' => [7 => 'Viejo']]])],
            [7 => 'Nuevo (1º ESO)']
        );

        $this->assertSame(['Nuevo (1º ESO)'], $rows[0]['changes'][0]['added']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'CurationEventTest|RecatalogServiceTest|CurationHistoryTest'`
Expected: FAIL — no `labels` key; stored label not used.

- [ ] **Step 3: Implement**

`CurationEvent::build()` — after the `why` block, before `$terms[$term] = $entry;`:

```php
            // Display only (TASK-065): the name each touched value had, so the
            // history can still name a term that is later deleted or that the
            // reader cannot open. Undo never reads it.
            $labels = [];
            $touched = array_merge($before, $after);
            foreach ($state['labels'] ?? [] as $id => $label) {
                if (in_array((int) $id, $touched, true) && '' !== trim((string) $label)) {
                    $labels[(int) $id] = (string) $label;
                }
            }
            if ($labels) {
                $entry['labels'] = $labels;
            }
```

Update the `@param` of `build()`:
`@param array<string,array{before:int[],after:int[],why?:array<int,string>,labels?:array<int,string>}> $dimensions`.

`RecatalogService::apply()` — where `$dimensions[$term]` is built:

```php
            $addedIds = array_values(array_diff($ids, $current['ids']));
            $dimensions[$term] = [
                'before' => $current['ids'],
                'after' => $ids,
                'why' => $current['reasons'],
                'labels' => $current['titles']
                    + $this->titlesFor($addedIds, array_fill_keys($addedIds, $term)),
            ];
```

`CurationHistory::linkChange()` — read the stored labels at the top:

```php
        $stored = [];
        foreach ($entry['labels'] ?? [] as $id => $label) {
            $stored[(int) $id] = (string) $label;
        }
```

and change every `self::title($id, $titles)` inside `linkChange()` to `self::title($id, $titles, $stored)`.
Replace `title()` with:

```php
    /**
     * @param array<int,string> $titles live titles
     * @param array<int,string> $stored labels the event stored when it was written
     */
    private static function title(int $id, array $titles, array $stored = []): string
    {
        foreach ([$titles[$id] ?? '', $stored[$id] ?? ''] as $candidate) {
            $candidate = trim((string) $candidate);
            if ('' !== $candidate) {
                return $candidate;
            }
        }
        return self::UNRESOLVED_PREFIX . $id;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'CurationEventTest|RecatalogServiceTest|CurationHistoryTest'`
Expected: PASS. Then `make test`: the whole suite still passes (undo tests read `before`/`after` only).

- [ ] **Step 5: Commit**

```bash
git add src/Service/CurationEvent.php src/Service/RecatalogService.php src/Service/Governance/CurationHistory.php test/Service
git commit -m "feat: curation events store the labels of the values they touch (TASK-065)

Model: Claude Opus 5.5"
```

---

### Task 3: Client history model — summary, retired text and paging

**Files:**
- Modify: `asset/js/core/historyModel.js`
- Modify: `asset/js/core/drawerModel.js` (`TERM_LABELS`)
- Test: `test/js/historyModel.test.js`

**Interfaces:**
- Consumes: rows from `CurationHistory::rows()` (Tasks 1-2), `TERM_LABELS`
- Produces:
  - `HISTORY_PAGE = 5`
  - `RETIRED_TEXT = 'término retirado (#%s)'`
  - `retiredId(title: string): string|null` — `'404'` for `'#404'`, else `null`
  - `summaryParts(row): Array<{label: string, added: number, removed: number, emptied: boolean}>`
  - `nextShown(total: number, shown: number): number` — `min(total, shown + HISTORY_PAGE)`
  - `TERM_LABELS['lrmi:educationalLevel'] === 'Curso'` (ADR-0022), plus `dcterms:creator` «Autoría»,
    `dcterms:publisher` «Editor», `dcterms:rightsHolder` «Titular de derechos», `dcterms:source` «Fuente»

- [ ] **Step 1: Write the failing tests**

Extend the import at the top of `test/js/historyModel.test.js` with
`summaryParts, retiredId, nextShown, HISTORY_PAGE, RETIRED_TEXT`, and append:

```js
test('el resumen de una fila cuenta añadidos y quitados por dimensión, con etiqueta humana', () => {
    const [r] = historyRows([row([
        { term: 'lrmi:teaches', added: ['A', 'B'], removed: [{ title: 'C', reason: '' }], emptied: false },
        { term: 'dcterms:license', added: ['https://x.test/l'], removed: [], emptied: false }
    ])]);
    assert.deepEqual(summaryParts(r), [
        { label: 'Saberes básicos', added: 2, removed: 1, emptied: false },
        { label: 'Licencia', added: 1, removed: 0, emptied: false }
    ]);
});

test('el curso se llama Curso y los campos de gobernanza tienen etiqueta', () => {
    const [r] = historyRows([row([
        { term: 'lrmi:educationalLevel', added: ['1º ESO'], removed: [], emptied: false },
        { term: 'dcterms:creator', added: ['Ana'], removed: [], emptied: false }
    ])]);
    assert.deepEqual(r.changes.map((c) => c.label), ['Curso', 'Autoría']);
});

test('un título #id es un término retirado; un título normal no', () => {
    assert.equal(retiredId('#404'), '404');
    assert.equal(retiredId('Fracciones (2º ESO)'), null);
    assert.equal(retiredId('#12 cosas'), null);
    assert.ok(RETIRED_TEXT.includes('%s'));
});

test('paginación de 5 en 5 (Review Focus 4)', () => {
    assert.equal(HISTORY_PAGE, 5);
    assert.equal(nextShown(0, 0), 0);
    assert.equal(nextShown(5, 0), 5);
    assert.equal(nextShown(6, 0), 5);
    assert.equal(nextShown(6, 5), 6);
    assert.equal(nextShown(12, 5), 10);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `node --test test/js/historyModel.test.js`
Expected: FAIL — the import of `summaryParts` does not exist.

- [ ] **Step 3: Implement**

`drawerModel.js` — in `TERM_LABELS`, change `'lrmi:educationalLevel': 'Etapa'` to
`'lrmi:educationalLevel': 'Curso'` and add, before the closing `})`:

```js
    'dcterms:creator': 'Autoría',
    'dcterms:publisher': 'Editor',
    'dcterms:rightsHolder': 'Titular de derechos',
    'dcterms:source': 'Fuente'
```

Then run `grep -rn "'Etapa'" test/js asset/js` and fix any test or caller that relied on the old label
for `lrmi:educationalLevel` (the Etapa navigation helper in `recatalog.js` is a literal, not this map).

`historyModel.js` — append:

```js
/** Events shown at first and added by each «Ver 5 más». */
export const HISTORY_PAGE = 5;

/**
 * Text for a term the server could not name (`CurationHistory::UNRESOLVED_PREFIX`):
 * the event predates stored labels and the term can no longer be read.
 */
export const RETIRED_TEXT = 'término retirado (#%s)';

/**
 * @param {string} title
 * @returns {string|null} the id of an unresolved `#<id>` title, or null
 */
export function retiredId(title) {
    const match = /^#([1-9][0-9]*)$/.exec(String(title));
    return match ? match[1] : null;
}

/**
 * One part per changed dimension, for the one-line summary of an event.
 * @param {object} row a row from historyRows()
 * @returns {Array<{label: string, added: number, removed: number, emptied: boolean}>}
 */
export function summaryParts(row) {
    return (row.changes || []).map((change) => ({
        label: change.label,
        added: (change.added || []).length,
        removed: (change.removed || []).length,
        emptied: Boolean(change.emptied)
    }));
}

/**
 * @param {number} total events in the history
 * @param {number} shown events shown now
 * @returns {number} events to show after one more page
 */
export function nextShown(total, shown) {
    return Math.min(total, shown + HISTORY_PAGE);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test-js`
Expected: PASS, 0 fail.

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/historyModel.js asset/js/core/drawerModel.js test/js
git commit -m "feat: history model with per-event summary, retired text and paging (TASK-065)

Model: Claude Opus 5.5"
```

---

### Task 4: The history renders as a paged timeline

**Files:**
- Modify: `asset/js/ui/drawerDetails.js` (`renderChange`, `renderHistory`)
- Modify: `asset/css/oer-master-view.css` (history rules around `.oer-history-entry`)
- Modify: `config/module.config.php` (`js_translate_strings`)

**Interfaces:**
- Consumes: `historyRows`, `historyChecked`, `summaryParts`, `retiredId`, `nextShown`, `RETIRED_TEXT`,
  `HISTORY_EMPTY_NOTICE`, `HISTORY_UNKNOWN_TEXT` from `core/historyModel.js` (Task 3)
- Produces: DOM `section.oer-drawer-history > ol.oer-history-list > li.oer-history-entry[.oer-history-undo] > details > summary.oer-history-line`,
  and `button.button.oer-history-more`

- [ ] **Step 1: Implement the rendering**

In `drawerDetails.js`, extend the import from `../core/historyModel.js` with
`summaryParts, retiredId, nextShown, RETIRED_TEXT`. Add, above `renderChange()` (skip `t()` if the file
already defines one):

```js
function t(text) {
    return Omeka.jsTranslate(text);
}

/** A title the server could not resolve reads as a retired term, not as a bare id. */
function displayTitle(title) {
    const id = retiredId(title);
    return null === id ? title : t(RETIRED_TEXT).replace('%s', id);
}

/** «Saberes básicos +2 −1 · Licencia +1», with translated labels. */
function summaryText(row) {
    return summaryParts(row).map((part) => {
        const counts = [];
        if (part.added) {
            counts.push(`+${part.added}`);
        }
        if (part.removed) {
            counts.push(`−${part.removed}`);
        }
        const tail = part.emptied ? ` (${t('vaciada')})` : '';
        return `${t(part.label)} ${counts.join(' ')}${tail}`;
    }).join(' · ');
}
```

In `renderChange()`, translate the label and use `displayTitle()` for both kinds of line:

```js
    label.textContent = change.emptied
        ? `${t(change.label)} (${t('vaciada')})`
        : t(change.label);
```
```js
        line.textContent = `+ ${displayTitle(title)}`;
```
```js
        line.textContent = `− ${displayTitle(value.title)}`;
```

Replace the `rows.forEach(...)` block at the end of `renderHistory()` (keep the two early returns for
«no se pudo leer» and «sin curaciones») with:

```js
    const list = document.createElement('ol');
    list.className = 'oer-history-list';
    section.appendChild(list);

    const entry = (row) => {
        const item = document.createElement('li');
        item.className = row.isUndo ? 'oer-history-entry oer-history-undo' : 'oer-history-entry';
        const details = document.createElement('details');
        const line = document.createElement('summary');
        line.className = 'oer-history-line';
        const head = document.createElement('span');
        head.className = 'oer-history-header';
        head.textContent = row.isUndo
            ? `${row.whenLabel} · ${row.contributor} · ${t('Deshecho')}`
            : `${row.whenLabel} · ${row.contributor}`;
        const what = document.createElement('span');
        what.className = 'oer-history-what';
        what.textContent = summaryText(row);
        line.append(head, what);
        details.appendChild(line);
        row.changes.forEach((change) => details.appendChild(renderChange(change, row.hasReasons)));
        item.appendChild(details);
        return item;
    };

    let shown = 0;
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'button oer-history-more';
    more.textContent = t('Ver 5 más');
    const showMore = () => {
        const next = nextShown(rows.length, shown);
        rows.slice(shown, next).forEach((row) => list.appendChild(entry(row)));
        shown = next;
        more.hidden = shown >= rows.length;
    };
    more.addEventListener('click', showMore);
    showMore();
    section.appendChild(more);
```

- [ ] **Step 2: CSS**

Replace the rules `.oer-history-entry` and `.oer-history-header` with:

```css
.oer-history-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.oer-history-entry {
    border-top: 1px solid var(--oer-rule);
    padding: 0.4em 0;
}

.oer-history-line {
    cursor: pointer;
}

.oer-history-header {
    display: block;
    color: var(--oer-muted);
    font-size: 0.9em;
}

.oer-history-what {
    display: block;
}

.oer-history-entry details[open] > .oer-history-line {
    margin-bottom: 0.4em;
}

.oer-history-more {
    margin-top: 0.5em;
}
```

Keep `.oer-history-undo` (left rule) as it is.

- [ ] **Step 3: Register the strings**

In `config/module.config.php`, in `js_translate_strings`, before the `...array_values(` line:

```php
        // TASK-065: history timeline and the labels the client now translates.
        'término retirado (#%s)', // @translate
        'Ver 5 más', // @translate
        'Deshecho', // @translate
        'vaciada', // @translate
        'Curso', // @translate
        'Saberes básicos', // @translate
        'Criterios de evaluación', // @translate
        'Materia', // @translate
        'Eje temático', // @translate
        'Licencia', // @translate
        'Autoría', // @translate
        'Editor', // @translate
        'Titular de derechos', // @translate
        'Fuente', // @translate
```

Before adding each one, check with `grep -n "'<string>'" config/module.config.php` that it is not
already in the list, and leave out the ones that are.

- [ ] **Step 4: Verify**

Run: `make test-js && make lint && make test`
Expected: all pass. `test/ModuleRuntimeTest.php` covers the config; if it asserts the exact list,
update it.

- [ ] **Step 5: Commit**

```bash
git add asset/js/ui/drawerDetails.js asset/css/oer-master-view.css config/module.config.php test
git commit -m "feat: curation history as a paged timeline with names (TASK-065)

Model: Claude Opus 5.5"
```

---

### Task 5: Visible sections, collapsible Media/Record/History, aligned bar

Defaults (decided while planning, recorded in the spec in Task 6): Media opens, Record and History
start closed. Curricular alignment and Governance are always open.

**Files:**
- Modify: `view/oer-manager/admin/index/drawer-details.phtml` (Media, Record and History sections)
- Modify: `asset/css/oer-master-view.css` (`.oer-area`, `.oer-area > h4`, `.oer-history-box > summary`,
  `.oer-anchor-bar`, `.oer-recatalog-undo`, `.oer-recatalog-undo-btn`)

**Interfaces:**
- Consumes: `.oer-history-box` and its `toggle` wiring in `drawerDetails.js` (contract unchanged)
- Produces: `details.oer-area-box > summary > h4` inside `.oer-area-media`, `.oer-area-record` and
  `.oer-area-history`

- [ ] **Step 1: Markup**

Media — wrap the heading and the content in a disclosure, open by default:

```php
            <section class="oer-area oer-area-media">
                <details class="oer-area-box" open>
                <summary><h4><?php echo $escape($translate(PanelAreas::LABELS['media'])); ?></h4></summary>
                <?php /* existing `if/else` content of the section, unchanged */ ?>
                </details>
            </section>
```

Record — the same, with `<details class="oer-area-box">` (closed).

History — the existing disclosure gets the class and a heading:

```php
    <section class="oer-area oer-area-history">
        <details class="oer-area-box oer-history-box" data-item-id="<?php echo (int) $identity['id']; ?>">
            <summary><h4><?php echo $escape($translate('Historial de curación')); ?></h4></summary>
            <div class="oer-history-body"></div>
        </details>
    </section>
```

- [ ] **Step 2: CSS**

Replace `.oer-area`, `.oer-area > h4` and `.oer-history-box > summary` with:

```css
.oer-area {
    border-top: 1px solid var(--oer-rule);
    padding-top: 0.75em;
    margin-bottom: 1.5em;
}

.oer-area > h4,
.oer-area-box > summary > h4 {
    margin: 0 0 0.5em;
    font-size: 0.8em;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--oer-ink);
}

/* A section that opens must look like one (owner's review, 2026-11-18): the
   heading keeps the section style and carries a visible chevron. */
.oer-area-box > summary {
    cursor: pointer;
    list-style: none;
}

.oer-area-box > summary::-webkit-details-marker {
    display: none;
}

.oer-area-box > summary > h4 {
    display: inline;
}

.oer-area-box > summary::before {
    content: "▸";
    display: inline-block;
    width: 1.1em;
    color: var(--oer-muted);
}

.oer-area-box[open] > summary::before {
    content: "▾";
}

.oer-area-box[open] > summary {
    margin-bottom: 0.5em;
}
```

Bar alignment — change `.oer-anchor-bar` to stretch its children, and replace `.oer-recatalog-undo` and
`.oer-recatalog-undo-btn` (keep `.oer-recatalog-undo:empty` and the `:hover`/`:focus` rule):

```css
.oer-anchor-bar {
    display: flex;
    align-items: stretch;
    gap: 0.75em;
    margin-top: 1em;
}

.oer-recatalog-undo {
    display: flex;
}

/* Same native button as «Re-catalogar» (same font size, padding and height);
   only the colours make it secondary. */
.oer-recatalog-undo-btn {
    margin: 0;
    background: transparent;
    border: 1px solid var(--oer-rule);
    color: var(--oer-ink);
}
```

- [ ] **Step 3: Verify**

Run: `make lint && make test && make test-js`
Expected: all pass. Then run `grep -rn "oer-history-box\|oer-area-media\|oer-area-record" test/` and
update any harness or test selector that the new markup changes.

- [ ] **Step 4: Commit**

```bash
git add view/oer-manager/admin/index/drawer-details.phtml asset/css/oer-master-view.css test
git commit -m "feat: visible, collapsible panel sections and an aligned re-cataloguing bar (TASK-065)

Model: Claude Opus 5.5"
```

---

### Task 6: Container verification, cause of `#<id>`, docs

**Files:**
- Create: `test/container/history-titles-check.php`
- Modify: `docs/superpowers/specs/2026-11-18-task-065-panel-usability-design.md` (A2 defaults, A5
  optional `labels` key, the governance `#1` finding)
- Modify: `docs/backlog.md` (TASK-065 row), `docs/project-memory.md`

- [ ] **Step 1: Read-only harness**

```php
<?php
// CLI only: these harnesses bootstrap Omeka and some write to the catalogue,
// so they must never run over HTTP (security review of TASK-067).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * TASK-065 (A5): why the curation history shows `#<id>`. Read only. For every
 * REA with curation events, lists each id the history references and whether
 * the authenticated user can read it, or the item no longer exists. Also counts
 * governance (typed) events, which the history painted as `#1` before TASK-065.
 *
 * Usage (inside the container):
 *   php history-titles-check.php <userEmail>
 */

chdir('/var/www/html');
require 'bootstrap.php';
require __DIR__ . '/cur-fixture.php';

use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\CurationHistory;
use OERManager\Service\RecatalogService;

$application = \Omeka\Mvc\Application::init(require 'application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$em = $services->get('Omeka\EntityManager');
$recatalog = $services->get(RecatalogService::class);

$email = (string) ($argv[1] ?? '');
if ('' === $email) {
    fwrite(STDERR, "usage: php history-titles-check.php <userEmail>\n");
    exit(2);
}
$user = curAuthenticate($services, $email);
printf("authenticated as %s (%s); read only\n\n", $user->getEmail(), $user->getRole());

$ids = $api->search('items', ['property' => [[
    'property' => 'dcterms:provenance', 'type' => 'in', 'text' => CurationEvent::MARKER,
]]], ['returnScalar' => 'id'])->getContent();

$typed = 0;
$missing = [];
$denied = [];
foreach ($ids as $itemId) {
    $events = $recatalog->events((int) $itemId);
    foreach ($events as $event) {
        if (CurationEvent::isTyped($event['payload'])) {
            $typed++;
        }
    }
    foreach (CurationHistory::referencedIds($events) as $id) {
        try {
            $api->read('items', $id);
        } catch (\Exception $e) {
            if (null === $em->find(\Omeka\Entity\Item::class, $id)) {
                $missing[$id] = true;
            } else {
                $denied[$id] = true;
            }
        }
    }
}

printf("REA with events: %d\ngovernance (typed) events: %d\n", count($ids), $typed);
printf("referenced ids that no longer exist: %d %s\n", count($missing), implode(',', array_keys($missing)));
printf("referenced ids this user cannot read: %d %s\n", count($denied), implode(',', array_keys($denied)));
```

If `RecatalogService` is not registered under its class name in the service manager, look it up the
way `test/container/undo-harness.php` does and use that call instead.

- [ ] **Step 2: Run it as an administrator and as a throwaway `rea_curador`**

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/history-titles-check.php <email>`
Expected: it prints the counts. Record the cause (typed events, deleted ids, unreadable ids) in the
TASK-065 backlog row. To get a throwaway REA user, reuse the creation and cleanup code of
`test/container/rea-roles-check.php`.

- [ ] **Step 3: Visual review in the container**

Open the master view as a non-administrator user with a REA role, open a REA with history, and check:
the section rules; the three chevrons; «Re-catalogar» and «Deshacer» at the same height; the timeline
(5 events, then «Ver 5 más»); names instead of ids; «término retirado (#n)» for old values that cannot
be resolved. Take screenshots for the owner.

- [ ] **Step 4: Docs**

- Spec A2: add «Media opens by default; Record and History start closed.»
- Spec A5 item 1: replace «in a new payload version that is read alongside the older ones» with «in an
  optional `labels` key of payload v1 (decode already ignores unknown keys; undo never reads it)».
- Spec A5: add «Governance events (payload v2) were painted as `#1`: fixed by rendering typed values as
  text.»
- Backlog TASK-065: part A implemented, the cause found in Step 2, the verification done, part B next.
- `docs/project-memory.md`: one entry with the non-obvious lesson (typed events and `intval`; labels
  stored for display only).

- [ ] **Step 5: Final checks and commit**

Run: `make lint && make test && make test-js`
Expected: all pass.

```bash
git add test/container/history-titles-check.php docs
git commit -m "docs: TASK-065 part A verified; history-titles container check

Model: Claude Opus 5.5"
```
