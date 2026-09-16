# TASK-028 slice 3b — governance editing implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a curator set licence, authorship, publisher, rights holder and source of one REA from the module panel, audited and reversible through the existing curation ledger.

**Architecture:** Pure pieces first (field catalogue, typed event payload, licence classification, integrity rule), then the shared writer extracted from `RecatalogService`, then the governance service on top of it, then controller, panel and JavaScript. The recataloguer keeps its public API and its v1 payload; only its internals move.

**Tech Stack:** PHP 8.4, Omeka S 4.2 API, PHPUnit on the host, native ES modules with `node --test`, no build step, no new dependencies.

**Spec:** `docs/superpowers/specs/2026-09-16-task-028-slice-3b-design.md` (decision on the event format: `docs/decisions/0020-curation-event-typed-values.md`)

## Global Constraints

- Target PHP 8.4. Do not use PHP 8.5 syntax even though the host tooling runs a newer PHP.
- `make lint` is PSR-12 and must be green before finishing any task. Do not introduce PHPStan or Psalm.
- No new Composer or npm dependencies. No bundler.
- Extend the Omeka core, never patch it. No custom Doctrine tables.
- UI strings stay in Spanish and carry `// @translate` **on the same line as the literal**: on its own line `extract-tagged-strings` walks back and extracts the wrong token.
- New documentation, comments in new files and commit messages are in English (`AGENTS.md`). Historical Spanish documents are preserved as they are.
- Work on branch `feature/task-028-slice-3b-governance` created from updated `main`.
- Container harnesses that write run **only against a disposable fixture**, never the live catalogue.
- Every task ends with `make lint` and `make test` green; tasks touching JavaScript also run `make test-js`.

---

### Task 1: Governance field catalogue

**Files:**
- Create: `src/Service/Governance/GovernanceFields.php`
- Test: `test/Service/Governance/GovernanceFieldsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `GovernanceFields::LICENCE = 'dcterms:license'`, `::CREATOR = 'dcterms:creator'`, `::PUBLISHER = 'dcterms:publisher'`, `::RIGHTS_HOLDER = 'dcterms:rightsHolder'`, `::SOURCE = 'dcterms:source'`
  - `GovernanceFields::all(): list<string>`
  - `GovernanceFields::isGovernanceTerm(string $term): bool`
  - `GovernanceFields::normalise(array $raw): array{values: array<string, list<array{type:string, value?:string, uri?:string, label?:string}>>, errors: array<string,string>}`

`$raw` maps a term to a list of raw strings from the form, e.g. `['dcterms:creator' => ['Ana Pérez', ' ', 'Luis Gil']]`. A term absent from `$raw` is untouched and must be absent from `values`. Literal fields produce `['type' => 'literal', 'value' => …]`; `dcterms:source` produces `['type' => 'uri', 'uri' => …]`. The licence and the publisher also come out as `literal`/`uri` here: Task 7 upgrades them to their CustomVocab type and attaches the label, because only the service knows the vocabulary.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\GovernanceFields;
use PHPUnit\Framework\TestCase;

final class GovernanceFieldsTest extends TestCase
{
    public function testTrimsValuesAndDropsEmptyOnes(): void
    {
        $result = GovernanceFields::normalise(['dcterms:creator' => ['  Ana Pérez ', '   ', 'Luis Gil']]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            ['type' => 'literal', 'value' => 'Ana Pérez'],
            ['type' => 'literal', 'value' => 'Luis Gil'],
        ], $result['values']['dcterms:creator']);
    }

    public function testKeepsAuthorOrderAsEntered(): void
    {
        $result = GovernanceFields::normalise(['dcterms:creator' => ['Zoe', 'Ana']]);

        $this->assertSame(['Zoe', 'Ana'], array_column($result['values']['dcterms:creator'], 'value'));
    }

    public function testSingleValuedFieldRejectsASecondValue(): void
    {
        $result = GovernanceFields::normalise(['dcterms:rightsHolder' => ['Consejería', 'Otro']]);

        $this->assertSame('too-many', $result['errors']['dcterms:rightsHolder']);
        $this->assertArrayNotHasKey('dcterms:rightsHolder', $result['values']);
    }

    public function testSourceMustBeHttpUri(): void
    {
        $result = GovernanceFields::normalise(['dcterms:source' => ['ftp://example.org/x']]);

        $this->assertSame('not-http-uri', $result['errors']['dcterms:source']);
    }

    public function testSourceAcceptsHttpsAndKeepsItAsUri(): void
    {
        $result = GovernanceFields::normalise(['dcterms:source' => ['https://example.org/rea/7']]);

        $this->assertSame([['type' => 'uri', 'uri' => 'https://example.org/rea/7']], $result['values']['dcterms:source']);
    }

    public function testClearingAFieldIsAnEmptyListNotAnAbsentTerm(): void
    {
        $result = GovernanceFields::normalise(['dcterms:creator' => ['']]);

        $this->assertSame([], $result['values']['dcterms:creator']);
        $this->assertArrayNotHasKey('dcterms:license', $result['values']);
    }

    public function testUnknownTermsAreIgnored(): void
    {
        $result = GovernanceFields::normalise(['dcterms:title' => ['Hola']]);

        $this->assertSame([], $result['values']);
        $this->assertSame([], $result['errors']);
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `make test` (or `vendor/bin/phpunit -c test/phpunit.xml --filter GovernanceFieldsTest`)
Expected: FAIL — `Class "OERManager\Service\Governance\GovernanceFields" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * The five governance fields of RF-015 and the rules for turning form input
 * into values (ADR-0013 §5.5, ADR-0019 for the licence).
 *
 * Pure on purpose: validation is what decides whether a write reaches the
 * catalogue, and nothing that touches the Omeka core can be tested on the host
 * (the TASK-008 harness limitation).
 */
final class GovernanceFields
{
    public const LICENCE = 'dcterms:license';
    public const CREATOR = 'dcterms:creator';
    public const PUBLISHER = 'dcterms:publisher';
    public const RIGHTS_HOLDER = 'dcterms:rightsHolder';
    public const SOURCE = 'dcterms:source';

    /** term => [multiple?, kind]. `kind` is the value shape before the vocabulary upgrade. */
    private const SPEC = [
        self::LICENCE => ['multiple' => false, 'kind' => 'uri'],
        self::CREATOR => ['multiple' => true, 'kind' => 'literal'],
        self::PUBLISHER => ['multiple' => false, 'kind' => 'literal'],
        self::RIGHTS_HOLDER => ['multiple' => false, 'kind' => 'literal'],
        self::SOURCE => ['multiple' => false, 'kind' => 'uri'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::SPEC);
    }

    public static function isGovernanceTerm(string $term): bool
    {
        return isset(self::SPEC[$term]);
    }

    public static function isMultiple(string $term): bool
    {
        return self::SPEC[$term]['multiple'] ?? false;
    }

    /**
     * @param array<string, list<string>> $raw term => raw strings from the form
     * @return array{values: array<string, list<array<string,string>>>, errors: array<string,string>}
     */
    public static function normalise(array $raw): array
    {
        $values = [];
        $errors = [];

        foreach (self::SPEC as $term => $spec) {
            if (!array_key_exists($term, $raw)) {
                continue;
            }
            $entries = [];
            foreach ((array) $raw[$term] as $candidate) {
                $text = trim((string) $candidate);
                if ('' === $text) {
                    continue;
                }
                if ('uri' === $spec['kind'] && !self::isHttpUri($text)) {
                    $errors[$term] = 'not-http-uri';
                    continue 2;
                }
                $entries[] = 'uri' === $spec['kind']
                    ? ['type' => 'uri', 'uri' => $text]
                    : ['type' => 'literal', 'value' => $text];
            }
            if (!$spec['multiple'] && count($entries) > 1) {
                $errors[$term] = 'too-many';
                continue;
            }
            $values[$term] = $entries;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private static function isHttpUri(string $candidate): bool
    {
        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true)
            && '' !== (string) parse_url($candidate, PHP_URL_HOST);
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `make test` then `make lint`
Expected: PASS, lint silent.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/GovernanceFields.php test/Service/Governance/GovernanceFieldsTest.php
git commit -m "feat(governance): field catalogue and input normalisation (TASK-028 3b)"
```

---

### Task 2: Typed curation event payload (v2)

**Files:**
- Modify: `src/Service/CurationEvent.php`
- Test: `test/Service/CurationEventTest.php` (add cases; keep every existing one untouched)

**Interfaces:**
- Consumes: `GovernanceFields::all()` from Task 1.
- Produces:
  - `CurationEvent::VERSION_TYPED = 2`, `CurationEvent::OP_GOVERNANCE = 'governance'`
  - `CurationEvent::buildTyped(array $terms, ?string $undoOf = null): ?array` where `$terms` is `term => ['before' => list<value>, 'after' => list<value>]` and a value is the shape Task 1 produces, optionally with `label`
  - `CurationEvent::isTyped(array $payload): bool`
  - `CurationEvent::scopeOf(array $payload): string` returning `'governance'` or `'curriculum'`
  - `CurationEvent::restoreValues(array $payload): array<string, list<array<string,string>>>`
  - `CurationEvent::expectedValues(array $payload): array<string, list<array<string,string>>>`

`decode()` must keep returning v1 payloads unchanged and now also accept v2. `build()`, `restoreTargets()`, `restoreReasons()` and `expectedTargets()` keep their current v1 behaviour: the recataloguer calls them and must not change.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter CurationEventTest`
Expected: FAIL — `Call to undefined method …::buildTyped()`.

- [ ] **Step 3: Write the implementation**

Add to `src/Service/CurationEvent.php`, leaving `VERSION`, `build`, `restoreTargets`, `restoreReasons`, `expectedTargets` and `changed` exactly as they are:

```php
    /** Payload version whose before/after carry typed values (ADR-0020). */
    public const VERSION_TYPED = 2;

    public const OP_GOVERNANCE = 'governance';

    /**
     * Event for values that are not links: literals and URIs (ADR-0020).
     * Comparison is by ordered list — reordering authors is a change — unlike
     * v1, where curriculum dimensions compare as sets.
     *
     * @param array<string,array{before:list<array<string,string>>,after:list<array<string,string>>}> $terms
     * @return array<string,mixed>|null null when nothing changed
     */
    public static function buildTyped(array $terms, ?string $undoOf = null): ?array
    {
        $changed = [];
        foreach ($terms as $term => $state) {
            $before = array_values($state['before']);
            $after = array_values($state['after']);
            if ($before === $after) {
                continue;
            }
            $changed[$term] = ['before' => $before, 'after' => $after];
        }
        if (!$changed) {
            return null;
        }
        return [
            'v' => self::VERSION_TYPED,
            'op' => null === $undoOf ? self::OP_GOVERNANCE : self::OP_UNDO,
            'undoOf' => $undoOf,
            'terms' => $changed,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function isTyped(array $payload): bool
    {
        return self::VERSION_TYPED === ($payload['v'] ?? null);
    }

    /**
     * Which service can replay this event. The terms say it, not the version:
     * an event never mixes curriculum terms with governance terms (ADR-0020 §4).
     *
     * @param array<string,mixed> $payload
     */
    public static function scopeOf(array $payload): string
    {
        foreach (array_keys($payload['terms'] ?? []) as $term) {
            if (Governance\GovernanceFields::isGovernanceTerm((string) $term)) {
                return 'governance';
            }
        }
        return 'curriculum';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,list<array<string,string>>>
     */
    public static function restoreValues(array $payload): array
    {
        $values = [];
        foreach ($payload['terms'] as $term => $entry) {
            $values[$term] = array_values($entry['before']);
        }
        return $values;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,list<array<string,string>>>
     */
    public static function expectedValues(array $payload): array
    {
        $values = [];
        foreach ($payload['terms'] as $term => $entry) {
            $values[$term] = array_values($entry['after']);
        }
        return $values;
    }
```

Change `decode()`'s version guard from equality to a whitelist, leaving the rest of the method as it is:

```php
        if (!is_array($data) || !in_array($data['v'] ?? null, [self::VERSION, self::VERSION_TYPED], true)) {
            return null;
        }
```

Extend `summary()` so a v2 payload reads correctly: where it builds `$head`, use

```php
        $head = self::OP_UNDO === $payload['op']
            ? 'Reversión' // @translate
            : (self::OP_GOVERNANCE === $payload['op'] ? 'Gobernanza' : 'Re-catalogación'); // @translate
```

and, when counting values per term for a typed payload, count entries rather than ids.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `make test` then `make lint`
Expected: PASS, including every pre-existing `CurationEventTest` case.

- [ ] **Step 5: Commit**

```bash
git add src/Service/CurationEvent.php test/Service/CurationEventTest.php
git commit -m "feat(curation): typed event payload v2 (ADR-0020)"
```

---

### Task 3: Licence state classification

**Files:**
- Create: `src/Service/Governance/LicenceStatus.php`
- Test: `test/Service/Governance/LicenceStatusTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `LicenceStatus::MISSING = 'missing'`, `::IN_VOCAB = 'in_vocab'`, `::OUTSIDE_VOCAB = 'outside_vocab'`, `::UNCHECKED = 'unchecked'`, and `LicenceStatus::of(list<array<string,string>> $values, ?array $vocabUris): string`.

`$vocabUris` is `null` when the setting resolves to no vocabulary, which yields `UNCHECKED`: the panel then shows the licence without a mark, because no curator can fix membership in a list that does not exist.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\LicenceStatus;
use PHPUnit\Framework\TestCase;

final class LicenceStatusTest extends TestCase
{
    private const VOCAB = ['https://creativecommons.org/licenses/by-sa/4.0/', 'https://creativecommons.org/licenses/by/4.0/'];

    public function testNoValuesIsMissing(): void
    {
        $this->assertSame(LicenceStatus::MISSING, LicenceStatus::of([], self::VOCAB));
    }

    public function testUriInTheVocabulary(): void
    {
        $values = [['type' => 'customvocab:2', 'uri' => 'https://creativecommons.org/licenses/by/4.0/']];

        $this->assertSame(LicenceStatus::IN_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }

    public function testUriOutsideTheVocabulary(): void
    {
        $values = [['type' => 'uri', 'uri' => 'https://example.org/licencia-propia']];

        $this->assertSame(LicenceStatus::OUTSIDE_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }

    public function testLiteralWithoutUriIsOutsideTheVocabulary(): void
    {
        $values = [['type' => 'literal', 'value' => 'ccbysa']];

        $this->assertSame(LicenceStatus::OUTSIDE_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }

    public function testWithoutAConfiguredVocabularyMembershipIsNotJudged(): void
    {
        $values = [['type' => 'uri', 'uri' => 'https://example.org/licencia-propia']];

        $this->assertSame(LicenceStatus::UNCHECKED, LicenceStatus::of($values, null));
        $this->assertSame(LicenceStatus::MISSING, LicenceStatus::of([], null));
    }

    public function testTrailingSlashAndCaseOfHostDoNotChangeMembership(): void
    {
        $values = [['type' => 'uri', 'uri' => 'https://CreativeCommons.org/licenses/by/4.0']];

        $this->assertSame(LicenceStatus::IN_VOCAB, LicenceStatus::of($values, self::VOCAB));
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter LicenceStatusTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * The three licence states ADR-0013 §3 asked for and slice 2 deferred to the
 * panel, plus the honest fourth one: with no vocabulary configured, membership
 * is not judged (ADR-0019 left validation open; this closes it).
 */
final class LicenceStatus
{
    public const MISSING = 'missing';
    public const IN_VOCAB = 'in_vocab';
    public const OUTSIDE_VOCAB = 'outside_vocab';
    public const UNCHECKED = 'unchecked';

    /**
     * @param list<array<string,string>> $values licence values, typed as in GovernanceFields
     * @param list<string>|null $vocabUris URIs of the configured vocabulary, null if unresolved
     */
    public static function of(array $values, ?array $vocabUris): string
    {
        if ([] === $values) {
            return self::MISSING;
        }
        if (null === $vocabUris) {
            return self::UNCHECKED;
        }
        $allowed = array_map([self::class, 'canonical'], $vocabUris);
        foreach ($values as $value) {
            $uri = self::canonical((string) ($value['uri'] ?? ''));
            if ('' === $uri || !in_array($uri, $allowed, true)) {
                return self::OUTSIDE_VOCAB;
            }
        }
        return self::IN_VOCAB;
    }

    /**
     * Host case and one trailing slash are not a different licence; the rest of
     * the URI is compared verbatim, because a path is case-sensitive.
     */
    private static function canonical(string $uri): string
    {
        $uri = rtrim(trim($uri), '/');
        $host = (string) parse_url($uri, PHP_URL_HOST);
        if ('' === $host) {
            return $uri;
        }
        return str_replace($host, strtolower($host), $uri);
    }
}
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `make test` then `make lint`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/LicenceStatus.php test/Service/Governance/LicenceStatusTest.php
git commit -m "feat(governance): classify licence into its three states"
```

---

### Task 4: Integrity warning for a licence outside the vocabulary

**Files:**
- Modify: `src/Service/Governance/IntegrityPolicy.php`
- Modify: `src/Service/IntegrityChecker.php` (`check()` and `project()`)
- Test: `test/Service/Governance/IntegrityPolicyTest.php`

**Interfaces:**
- Consumes: `LicenceStatus` from Task 3, `GovernanceSettings::LICENCE_VOCAB_ID`.
- Produces: `IntegrityPolicy::issuesFor(array $values, array $requiredTerms, bool $checkLinks, ?array $licenceVocabUris = null)` — a fourth optional parameter, so every existing caller keeps working; the projected value shape gains `'uri' => string`.

- [ ] **Step 1: Write the failing test**

```php
    public function testLicenceOutsideTheVocabularyWarnsOnce(): void
    {
        $issues = IntegrityPolicy::issuesFor(
            ['dcterms:license' => [['type' => 'uri', 'hasResource' => true, 'hasUri' => true, 'uri' => 'https://example.org/mia']]],
            [],
            false,
            ['https://creativecommons.org/licenses/by/4.0/']
        );

        $codes = array_column($issues, 'code');
        $this->assertSame(['license_not_in_vocab'], $codes);
    }

    public function testLicenceInTheVocabularyIsSilent(): void
    {
        $issues = IntegrityPolicy::issuesFor(
            ['dcterms:license' => [['type' => 'uri', 'hasResource' => true, 'hasUri' => true, 'uri' => 'https://creativecommons.org/licenses/by/4.0/']]],
            [],
            false,
            ['https://creativecommons.org/licenses/by/4.0/']
        );

        $this->assertSame([], array_column($issues, 'code'));
    }

    public function testWithoutAVocabularyMembershipIsNotWarnedAbout(): void
    {
        $issues = IntegrityPolicy::issuesFor(
            ['dcterms:license' => [['type' => 'uri', 'hasResource' => true, 'hasUri' => true, 'uri' => 'https://example.org/mia']]],
            [],
            false,
            null
        );

        $this->assertSame([], array_column($issues, 'code'));
    }

    public function testANonUriLicenceWarnsOnlyAboutNotBeingAUri(): void
    {
        $issues = IntegrityPolicy::issuesFor(
            ['dcterms:license' => [['type' => 'literal', 'hasResource' => true, 'hasUri' => false, 'uri' => '']]],
            [],
            false,
            ['https://creativecommons.org/licenses/by/4.0/']
        );

        $this->assertSame(['license_not_uri'], array_column($issues, 'code'));
    }
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IntegrityPolicyTest`
Expected: FAIL — no `license_not_in_vocab` issue is produced.

- [ ] **Step 3: Write the implementation**

In `IntegrityPolicy::issuesFor()`, after the existing `license_not_uri` loop:

```php
        // ADR-0020 / slice 3b: membership in the configured vocabulary. Only
        // evaluated when the setting resolves; unconfigured it stays silent,
        // because no curator can fix a list that does not exist. A value that
        // is not a URI already warned above and is not warned about twice.
        if (null !== $licenceVocabUris) {
            foreach ($licenceValues as $value) {
                if (!($value['hasUri'] ?? false)) {
                    continue;
                }
                if (LicenceStatus::IN_VOCAB !== LicenceStatus::of([['uri' => (string) ($value['uri'] ?? '')]], $licenceVocabUris)) {
                    $issues[] = [
                        'severity' => 'warning',
                        'code' => 'license_not_in_vocab',
                        'field' => self::LICENSE_TERM,
                        'message' => 'La licencia no está en la lista de licencias aprobadas.', // @translate
                    ];
                }
            }
        }
```

In `IntegrityChecker::project()`, add the URI to the projected value:

```php
                    'uri' => trim((string) $value->uri()),
```

In `IntegrityChecker::check()`, pass the vocabulary URIs through. The checker already receives the settings service used elsewhere in the class; read `GovernanceSettings::LICENCE_VOCAB_ID`, resolve the vocabulary through the `VocabEntries` reader built in Task 5, and pass `null` when it does not resolve.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `make test` then `make lint`
Expected: PASS. `IntegrityChecker` itself has no host test; Task 12 covers it in the container.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/IntegrityPolicy.php src/Service/IntegrityChecker.php test/Service/Governance/IntegrityPolicyTest.php
git commit -m "feat(integrity): warn when the licence is outside the configured vocabulary"
```

---

### Task 5: Vocabulary entries reader and the publisher setting

**Files:**
- Create: `src/Service/Governance/VocabEntries.php`
- Modify: `src/Service/GovernanceSettings.php`, `src/Service/ConfigPayload.php`, `src/Form/ConfigForm.php`, `config/module.config.php`
- Test: `test/Service/Governance/VocabEntriesTest.php`, `test/Service/ConfigPayloadTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `GovernanceSettings::PUBLISHER_VOCAB_ID = 'oermanager_publisher_vocab_id'`
  - `VocabEntries::__construct(?int $vocabId, callable $reader)` where the reader returns `list<array{uri?:string, label?:string, value?:string}>`
  - `VocabEntries::entries(): list<array{uri?:string, label?:string, value?:string}>`, `::uris(): ?list<string>` (null when unresolved), `::isAvailable(): bool`, `::dataType(): ?string` returning `customvocab:<id>` or null

This mirrors `ResourceTypeVocab`: a callable reader keeps the core out of the host tests.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\VocabEntries;
use PHPUnit\Framework\TestCase;

final class VocabEntriesTest extends TestCase
{
    public function testReadsEntriesOnceAndExposesUris(): void
    {
        $calls = 0;
        $vocab = new VocabEntries(2, function (int $id) use (&$calls): array {
            $calls++;
            return [['uri' => 'https://x/by/4.0/', 'label' => 'CC BY 4.0']];
        });

        $this->assertSame(['https://x/by/4.0/'], $vocab->uris());
        $this->assertSame('customvocab:2', $vocab->dataType());
        $this->assertTrue($vocab->isAvailable());
        $vocab->uris();
        $this->assertSame(1, $calls);
    }

    public function testUnsetSettingMeansUnresolvedNotEmpty(): void
    {
        $vocab = new VocabEntries(null, static fn (int $id): array => []);

        $this->assertNull($vocab->uris());
        $this->assertNull($vocab->dataType());
        $this->assertFalse($vocab->isAvailable());
    }

    public function testAReaderThatThrowsDegradesInsteadOfBreaking(): void
    {
        $vocab = new VocabEntries(9, static function (int $id): array {
            throw new \RuntimeException('CustomVocab is not active');
        });

        $this->assertNull($vocab->uris());
        $this->assertFalse($vocab->isAvailable());
    }
}
```

- [ ] **Step 2: Run the test and confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter VocabEntriesTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Entries of a CustomVocab identified by a setting (ADR-0013, "seed, do not
 * own"). Never identified by label: labels change with i18n and with admin
 * edits. Unresolved means unresolved, not empty — the difference decides
 * whether the panel judges vocabulary membership at all.
 */
final class VocabEntries
{
    private ?int $vocabId;
    /** @var callable(int):list<array<string,string>> */
    private $reader;
    /** @var list<array<string,string>>|null */
    private ?array $entries = null;
    private bool $resolved = false;

    public function __construct(?int $vocabId, callable $reader)
    {
        $this->vocabId = $vocabId && $vocabId > 0 ? $vocabId : null;
        $this->reader = $reader;
    }

    /** @return list<array<string,string>> */
    public function entries(): array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            if (null !== $this->vocabId) {
                try {
                    $this->entries = array_values(($this->reader)($this->vocabId));
                } catch (\Throwable $e) {
                    $this->entries = null;
                }
            }
        }
        return $this->entries ?? [];
    }

    /** @return list<string>|null null when the vocabulary does not resolve */
    public function uris(): ?array
    {
        $this->entries();
        if (null === $this->entries) {
            return null;
        }
        return array_values(array_filter(array_map(
            static fn (array $entry): string => trim((string) ($entry['uri'] ?? '')),
            $this->entries
        ), static fn (string $uri): bool => '' !== $uri));
    }

    public function dataType(): ?string
    {
        $this->entries();
        return null === $this->entries || null === $this->vocabId ? null : 'customvocab:' . $this->vocabId;
    }

    public function isAvailable(): bool
    {
        return [] !== $this->entries();
    }
}
```

Add the constant to `GovernanceSettings`:

```php
    /** CustomVocab of publishers (RF-015). Seeded by nobody: the admin creates it. */
    public const PUBLISHER_VOCAB_ID = 'oermanager_publisher_vocab_id';
```

Wire it through `ConfigPayload` exactly as `RESOURCE_TYPE_VOCAB_ID` is wired, add the matching field to `ConfigForm` labelled «CustomVocab de organismos editores (dcterms:publisher)» with `// @translate` on the literal, and register `VocabEntries` factories for the licence and the publisher vocabularies in `config/module.config.php` alongside the existing `ResourceTypeVocab` factory. The reader passed to each factory asks the API for `custom_vocabs` by id and returns its URIs with labels, or its terms as `['value' => …]` for a term-typed vocabulary.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `make test` then `make lint`
Expected: PASS, including the `ConfigPayloadTest` case you extend for the new setting.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/VocabEntries.php src/Service/GovernanceSettings.php src/Service/ConfigPayload.php src/Form/ConfigForm.php config/module.config.php test/Service/Governance/VocabEntriesTest.php test/Service/ConfigPayloadTest.php
git commit -m "feat(config): publisher vocabulary setting and a shared CustomVocab reader"
```

---

### Task 6: Extract `CurationWriter` from `RecatalogService`

**Files:**
- Create: `src/Service/Curation/CurationWriter.php`
- Modify: `src/Service/RecatalogService.php`, `config/module.config.php`
- Verify: `test/container/undo-harness.php` (run it; do not change it)

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `CurationWriter::stamp(): string` — `Y-m-d\TH:i:s.uP`
  - `CurationWriter::propertyId(string $term): ?int`
  - `CurationWriter::annotationValues(array $map): array`
  - `CurationWriter::annotation(string $contributor, string $when, string $provenance, string $description = ''): array`
  - `CurationWriter::eventValue(array $payload, string $contributor, string $when): ?array`
  - `CurationWriter::commit(int $itemId, array $clearPropertyIds, array $data): void`

**Behaviour must not change.** `RecatalogService` keeps every public method, its v1 payload and its `provenance` texts. This is the riskiest task in the plan and no host test covers it.

- [ ] **Step 1: Run the container harness before touching anything, to have a baseline**

Run, against a disposable fixture environment:
`docker exec <container> php /var/www/html/modules/OERManager/test/container/undo-harness.php`
Expected: record the exact pass/fail counts. They must be identical at the end of the task.

- [ ] **Step 2: Create `CurationWriter` by moving the code verbatim**

Move the bodies of `RecatalogService::propertyId()`, `annotationValues()`, `annotation()` and `eventValue()` into the new class, and add `stamp()` and `commit()`:

```php
    /** One microsecond-precision stamp shared by the event and every annotation (ADR-0015). */
    public function stamp(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
    }

    /**
     * Targeted clear plus append. `isPartial` is not a per-property merge: in
     * append mode Omeka neither reuses nor deletes the values of properties
     * absent from $data, so title, description and alignment survive.
     *
     * @param list<int> $clearPropertyIds
     * @param array<string,mixed> $data
     */
    public function commit(int $itemId, array $clearPropertyIds, array $data): void
    {
        $data['clear_property_values'] = $clearPropertyIds;
        $this->api->update('items', $itemId, $data, [], ['isPartial' => true, 'collectionAction' => 'append']);
    }
```

`annotation()` takes the provenance text as an argument instead of building `'OERManager re-catalogación de %s'` itself, so governance can pass its own. `RecatalogService` passes `sprintf('OERManager re-catalogación de %s', $term)`, preserving today's text exactly.

- [ ] **Step 3: Make `RecatalogService` delegate**

Inject `CurationWriter` in the constructor, replace the four private methods with calls to it, and replace the inline `$now = (new \DateTimeImmutable())…` with `$this->writer->stamp()` and the inline `$this->api->update(...)` with `$this->writer->commit(...)`. Register the writer as a service and add it to the `RecatalogService` factory in `config/module.config.php`.

- [ ] **Step 4: Run every check**

Run: `make lint`, `make test`, then the container harness again:
`docker exec <container> php /var/www/html/modules/OERManager/test/container/undo-harness.php`
Expected: lint silent, PHP suite green, harness counts **identical to Step 1**. If they differ, revert and redo the move; do not proceed.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Curation/CurationWriter.php src/Service/RecatalogService.php config/module.config.php
git commit -m "refactor(curation): extract CurationWriter, no behaviour change"
```

---

### Task 7: `GovernanceService`

**Files:**
- Create: `src/Service/GovernanceService.php`
- Modify: `config/module.config.php`
- Verify: Task 12's harness (no host test is possible; the class needs the core)

**Interfaces:**
- Consumes: `GovernanceFields`, `CurationEvent::buildTyped/restoreValues/expectedValues`, `LicenceStatus`, `VocabEntries`, `CurationWriter`.
- Produces:
  - `GovernanceService::read(ItemRepresentation $item): array{values: array<string,list<array<string,string>>>, licenceStatus: string, options: array{licence: list<array<string,string>>, publisher: list<array<string,string>>}, notices: array<string,string>, defaultRightsHolder: string}`
  - `GovernanceService::apply(int $itemId, array $raw, string $contributor, ?string $undoOf = null): array{updated: bool, unchanged?: bool, errors?: array<string,string>, values?: array<string,list<array<string,string>>>, event?: array<string,string>}`
  - `GovernanceService::undoEvent(int $itemId, array $event, string $contributor, bool $force): array`

Rules the implementation must follow, all from the spec:

1. `apply()` normalises with `GovernanceFields::normalise()`; any error returns `['updated' => false, 'errors' => …]` and writes nothing.
2. The licence and the publisher are upgraded to the vocabulary data type: when `VocabEntries::dataType()` resolves and the submitted URI (licence) or text (publisher) matches an entry, the written value takes that type and the entry's label. Unmatched licence values stay `['type' => 'uri', 'uri' => …]`; unmatched publisher values stay literal.
3. The previous state is read **before** writing, from the item's current values, in the same typed shape.
4. `CurationEvent::buildTyped()` decides whether anything changed. Null means nothing is written and the answer is `['updated' => false, 'unchanged' => true]`.
5. Each written value carries `CurationWriter::annotation($contributor, $when, sprintf('OERManager edición de gobernanza de %s', $term))`.
6. The event value is appended; `dcterms:provenance` is never in the clear list.
7. `undoEvent()` compares the item's current values with `CurationEvent::expectedValues()`; unless `$force`, a mismatch returns `['updated' => false, 'error' => 'stale', 'terms' => …]`. Otherwise it calls `apply()` with `CurationEvent::restoreValues()` and the event's stamp as `$undoOf`.

- [ ] **Step 1: Write the class**

```php
<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;
use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\Governance\LicenceStatus;
use OERManager\Service\Governance\VocabEntries;

/**
 * Reads and writes the five governance fields of RF-015, through the same
 * ledger as recataloguing (ADR-0020). Not testable on the host: it needs the
 * Omeka core. Everything it decides lives in the pure pieces it calls.
 */
class GovernanceService
{
    public function __construct(
        private ApiManager $api,
        private CurationWriter $writer,
        private VocabEntries $licenceVocab,
        private VocabEntries $publisherVocab,
        private Settings $settings
    ) {
    }

    /** @return array<string,mixed> */
    public function read(ItemRepresentation $item): array
    {
        $values = $this->currentValues($item);
        return [
            'values' => $values,
            'licenceStatus' => LicenceStatus::of($values[GovernanceFields::LICENCE] ?? [], $this->licenceVocab->uris()),
            'options' => [
                'licence' => $this->licenceVocab->entries(),
                'publisher' => $this->publisherVocab->entries(),
            ],
            'notices' => $this->notices(),
            'defaultRightsHolder' => (string) $this->settings->get(GovernanceSettings::DEFAULT_RIGHTS_HOLDER, ''),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(int $itemId, array $raw, string $contributor, ?string $undoOf = null): array
    {
        $normalised = GovernanceFields::normalise($raw);
        if ($normalised['errors']) {
            return ['updated' => false, 'errors' => $normalised['errors']];
        }

        $when = $this->writer->stamp();
        $item = $this->api->read('items', $itemId)->getContent();
        $current = $this->currentValues($item);

        $data = [];
        $clear = [];
        $terms = [];
        foreach ($normalised['values'] as $term => $submitted) {
            $propertyId = $this->writer->propertyId($term);
            if (null === $propertyId) {
                continue;
            }
            $after = $this->withVocabularyType($term, $submitted);
            $terms[$term] = ['before' => $current[$term] ?? [], 'after' => $after];
            $clear[] = $propertyId;
            if ($after) {
                $data[$term] = $this->buildValues($propertyId, $after, $contributor, $when, $term);
            }
        }
        if (!$clear) {
            return ['updated' => false, 'properties' => []];
        }

        // Nothing changed means nothing is written: rewriting identical values
        // would reseal every annotation with a new author and time.
        $event = CurationEvent::buildTyped($terms, $undoOf);
        if (null === $event) {
            return ['updated' => false, 'unchanged' => true];
        }

        $eventValue = $this->writer->eventValue($event, $contributor, $when);
        if ($eventValue) {
            $data['dcterms:provenance'] = [$eventValue];
        }
        $this->writer->commit($itemId, $clear, $data);

        $fresh = $this->api->read('items', $itemId)->getContent();
        return [
            'updated' => true,
            'values' => $this->currentValues($fresh),
            'event' => ['when' => $when, 'summary' => CurationEvent::summary($event)],
        ];
    }

    /** @return array<string,mixed> */
    public function undoEvent(int $itemId, array $event, string $contributor, bool $force = false): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $current = $this->currentValues($item);
        if (!$force) {
            $stale = [];
            foreach (CurationEvent::expectedValues($event['payload']) as $term => $expected) {
                if (($current[$term] ?? []) !== $expected) {
                    $stale[] = $term;
                }
            }
            if ($stale) {
                return ['updated' => false, 'error' => 'stale', 'terms' => $stale];
            }
        }
        $restore = [];
        foreach (CurationEvent::restoreValues($event['payload']) as $term => $values) {
            $restore[$term] = array_map(
                static fn (array $value): string => (string) ($value['uri'] ?? $value['value'] ?? ''),
                $values
            );
        }
        $result = $this->apply($itemId, $restore, $contributor, $event['when']);
        $result['undoneAt'] = $event['when'];
        return $result;
    }
}
```

Write the four private helpers it uses: `currentValues()` projects the item's values for `GovernanceFields::all()` into the typed shape, preserving order; `withVocabularyType()` upgrades a licence or publisher value to `VocabEntries::dataType()` and attaches the entry label when it matches; `buildValues()` builds the API payload per value, attaching `CurationWriter::annotation($contributor, $when, sprintf('OERManager edición de gobernanza de %s', $term))`; `notices()` returns one message per unresolved vocabulary.

- [ ] **Step 2: Register it**

Add a factory in `config/module.config.php` injecting `Omeka\ApiManager`, `CurationWriter`, the two `VocabEntries` instances and `Omeka\Settings`.

- [ ] **Step 3: Check it loads in the container**

Run: `docker exec <container> php -r 'require "/var/www/html/bootstrap.php"; $a=Omeka\Mvc\Application::init(require "/var/www/html/application/config/application.config.php"); $a->getServiceManager()->get(OERManager\Service\GovernanceService::class); echo "ok\n";'`
Expected: `ok`. A factory wiring mistake surfaces here, not in the browser.

- [ ] **Step 4: Run lint and tests**

Run: `make lint` and `make test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add src/Service/GovernanceService.php config/module.config.php
git commit -m "feat(governance): service that reads, writes and reverts governance fields"
```

---

### Task 8: `governance-apply` endpoint, ACL and undo routing

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`, `Module.php`, `config/module.config.php`
- Create: `src/Service/Curation/UndoRouter.php`

**Interfaces:**
- Consumes: `GovernanceService`, `RecatalogService::lastEvent()`, `CurationEvent::scopeOf()`.
- Produces:
  - `IndexController::governanceApplyAction()` answering `{updated, values, licenceStatus, integrity, event}` or `{updated:false, error}` / `{updated:false, errors:{term:code}}`
  - `UndoRouter::undo(int $itemId, string $contributor, bool $force): array`

- [ ] **Step 1: Write the controller action**

Copy the guard sequence of `recatalogApplyAction()` verbatim — POST-only redirect, CSRF check answering `['updated' => false, 'error' => 'csrf']`, `PermissionDeniedException` answering `denied`, `\RuntimeException` answering its message, and any other exception logged and answered as `unexpected`. Collect the five fields from the POST into the `$raw` shape of Task 1, call `GovernanceService::apply()`, and add the recomputed integrity from `IntegrityChecker::check($item, true)` to the answer.

- [ ] **Step 2: Add the privilege**

In `Module::onBootstrap()`, add `'governance-apply'` to the existing privilege list granted to `['editor', 'site_admin', 'reviewer']`. Do not create a second `allow()` block.

- [ ] **Step 3: Route undo by scope**

```php
final class UndoRouter
{
    public function __construct(
        private \OERManager\Service\RecatalogService $recatalog,
        private \OERManager\Service\GovernanceService $governance
    ) {
    }

    public function undo(int $itemId, string $contributor, bool $force = false): array
    {
        $event = $this->recatalog->lastEvent($itemId);
        if (null === $event) {
            return ['updated' => false, 'error' => 'no-event'];
        }
        return 'governance' === CurationEvent::scopeOf($event['payload'])
            ? $this->governance->undoEvent($itemId, $event, $contributor, $force)
            : $this->recatalog->undo($itemId, $contributor, $force);
    }
}
```

Point `recatalogUndoAction()` at the router. The endpoint name and its JSON contract do not change: `asset/js/ui/recatalog.js` keeps working untouched.

- [ ] **Step 4: Verify in the container**

Run the service-loading check of Task 7 for `UndoRouter`, then `make lint` and `make test`.
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/IndexController.php Module.php src/Service/Curation/UndoRouter.php config/module.config.php
git commit -m "feat(governance): governance-apply endpoint, ACL privilege and undo routing"
```

---

### Task 9: Governance block in `drawer-details`

**Files:**
- Modify: `src/Controller/Admin/IndexController.php` (`drawerDetailsAction`), `src/Service/PanelAreas.php`, `view/oer-manager/admin/index/drawer-details.phtml`
- Test: `test/Service/PanelAreasTest.php`

**Interfaces:**
- Consumes: `GovernanceService::read()`.
- Produces: the view variable `governance` with the `read()` payload plus `canEdit` (bool) and `csrf` (string), and the panel area key `'governance'`.

- [ ] **Step 1: Write the failing test**

```php
    public function testGovernanceAreaSitsBetweenRecordAndIntegrity(): void
    {
        $this->assertSame(
            ['alignment', 'media', 'record', 'governance', 'integrity'],
            PanelAreas::ORDER
        );
        $this->assertSame('Licencia y autoría', PanelAreas::LABELS['governance']);
    }
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter PanelAreasTest`
Expected: FAIL — the key is missing.

- [ ] **Step 3: Implement**

Add `'governance'` to `PanelAreas::ORDER` and `PanelAreas::LABELS` (`'governance' => 'Licencia y autoría', // @translate`), extend `PanelAreas::build()` so the area reports `STATE_EMPTY` when all five fields are empty, and set the `governance` view variable in `drawerDetailsAction()`. `canEdit` comes from `$this->acl->userIsAllowed(Controller\Admin\IndexController::class, 'governance-apply')`; `csrf` from `$this->csrfValidator()->getHash()`.

In the template, render the read view: one row per field, the licence with its state (a `⚠` marker with the text «No está en la lista de licencias aprobadas» only for `outside_vocab`), missing data as muted text without a glyph, and an «Editar» button rendered only when `canEdit`. Add one notice per unconfigured vocabulary, e.g. «Sin vocabulario de organismos configurado: se guardará como texto libre.» with `// @translate` on the literal.

- [ ] **Step 4: Run the tests**

Run: `make lint` and `make test`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/IndexController.php src/Service/PanelAreas.php view/oer-manager/admin/index/drawer-details.phtml test/Service/PanelAreasTest.php
git commit -m "feat(panel): read view for licence and authorship"
```

---

### Task 10: `governanceModel.js`

**Files:**
- Create: `asset/js/core/governanceModel.js`
- Test: `test/js/governanceModel.test.js`

**Interfaces:**
- Consumes: nothing (pure, no DOM).
- Produces: `buildPayload(formState)`, `validate(formState)`, `licenceState(values, vocabUris)`, `rows(governance)` — mirroring Tasks 1 and 3 so the form can warn before a round trip. The server remains the authority.

- [ ] **Step 1: Write the failing test**

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { buildPayload, validate, licenceState } from '../../asset/js/core/governanceModel.js';

test('drops empty authors and keeps their order', () => {
  const payload = buildPayload({ creator: ['Ana', '  ', 'Luis'] });
  assert.deepEqual(payload['dcterms:creator'], ['Ana', 'Luis']);
});

test('a source that is not http is rejected before sending', () => {
  assert.deepEqual(validate({ source: 'ftp://x/y' }), { 'dcterms:source': 'not-http-uri' });
  assert.deepEqual(validate({ source: 'https://x/y' }), {});
});

test('licence state matches the server classification', () => {
  const vocab = ['https://x/by/4.0/'];
  assert.equal(licenceState([], vocab), 'missing');
  assert.equal(licenceState([{ uri: 'https://x/by/4.0/' }], vocab), 'in_vocab');
  assert.equal(licenceState([{ uri: 'https://x/mia/' }], vocab), 'outside_vocab');
  assert.equal(licenceState([{ uri: 'https://x/mia/' }], null), 'unchecked');
});

test('a cleared field is sent as an empty list, not omitted', () => {
  const payload = buildPayload({ creator: [], licence: '' });
  assert.deepEqual(payload['dcterms:creator'], []);
  assert.deepEqual(payload['dcterms:license'], []);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `make test-js`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the module**

Export the four functions, with no DOM access and no imports from `ui/`. Keep the term strings identical to `GovernanceFields`.

- [ ] **Step 4: Run the tests**

Run: `make test-js`, then `make lint`
Expected: green.

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/governanceModel.js test/js/governanceModel.test.js
git commit -m "feat(js): pure model for the governance form"
```

---

### Task 11: `governance.js` and styles

**Files:**
- Create: `asset/js/ui/governance.js`
- Modify: `asset/js/ui/drawerDetails.js`, `asset/js/main.js`, `asset/css/oer-master-view.css`

**Interfaces:**
- Consumes: `governanceModel.js`, the `governance` block rendered by Task 9, the `governance-apply` endpoint of Task 8.
- Produces: no exports other than an `init(root)` used by `drawerDetails.js`.

- [ ] **Step 1: Implement the edit flow**

«Editar» swaps the read rows for the form; authors are a list with add and remove; the rights holder input starts filled with `governance.defaultRightsHolder` **only when the REA has no rights holder of its own**, and nothing is written until the curator saves; save posts the payload with the CSRF hash; the answer repaints the section, the integrity area and the Licence cell of the row, using the values the server returned. Errors from `errors` are shown beside their field; `csrf` and `denied` show the panel-level message already used by `recatalog.js`.

- [ ] **Step 2: Style it with the existing tokens**

Reuse the tokens of `oer-master-view.css`. Do not add colour values: the `contrast` test reads the tokens from the CSS and fails if the palette gets worse.

- [ ] **Step 3: Run the checks**

Run: `make test-js`, `make lint`, `make test`
Expected: green. `node --check asset/js/ui/governance.js` must also pass, as the UI layer has no unit tests by design.

- [ ] **Step 4: Commit**

```bash
git add asset/js/ui/governance.js asset/js/ui/drawerDetails.js asset/js/main.js asset/css/oer-master-view.css
git commit -m "feat(panel): edit licence and authorship from the drawer"
```

---

### Task 12: Container harness

**Files:**
- Create: `test/container/governance-check.php`

- [ ] **Step 1: Write the read-only half**

Mirror the structure of `test/container/licence-check.php`: a `check()`/`skip()` pair, a numbered section per concern, a final count and `exit(1)` on failure. Verify: coverage of the five fields over the real REA; the URIs of the configured licence vocabulary; the three-state classification; that `governance-apply` is in the ACL for `editor`, `site_admin` and `reviewer` and absent for `author`; and that `GovernanceService::read()` answers for a real REA.

- [ ] **Step 2: Run it**

Run: `docker exec <container> php /var/www/html/modules/OERManager/test/container/governance-check.php`
Expected: all checks OK, exit 0. This half writes nothing.

- [ ] **Step 3: Write the `--write` half**

Guarded by `--write <email> [item_id]` like `licence-check.php`, and documented in the file header as **disposable fixtures only**. On the fixture REA: save a licence and two authors, then assert (a) `dcterms:title`, `dcterms:description` and the alignment values are untouched — the `ValueHydrator` canary; (b) every written value has an annotation whose `dcterms:modified` equals the event stamp; (c) the event is private, v2, and its payload decodes; (d) `UndoRouter::undo()` restores the exact previous values and writes its own event; (e) saving the same values again writes nothing and answers `unchanged`; (f) after changing a value by another route, undo answers `stale`.

- [ ] **Step 4: Run both halves against a disposable fixture**

Expected: 0 FAIL. Record the counts for the backlog entry.

- [ ] **Step 5: Commit**

```bash
git add test/container/governance-check.php
git commit -m "test(container): harness for governance writes and undo"
```

---

### Task 13: Close the slice in the governance documents

**Files:**
- Modify: `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md`, `docs/requirements.md`

- [ ] **Step 1: Update the backlog**

Extend the TASK-028 row: slice 3b done, what was delivered, the measured harness counts, what was left out and why, and anything found while implementing that the plan did not foresee.

- [ ] **Step 2: Update traceability and requirements**

Add slice 3b to the RF-002/RF-015 rows. RF-015 moves from `propuesto` to `aceptado`, citing ADR-0013, ADR-0019 and ADR-0020.

- [ ] **Step 3: Update the project memory**

One entry with the lessons, not the changelog: what the design did not anticipate, and what the next slice inherits.

- [ ] **Step 4: Run the full suite one last time**

Run: `make lint`, `make test`, `make test-js`, both container harnesses.
Expected: green, and the counts you write in the backlog must be the ones you just saw.

- [ ] **Step 5: Commit and open the pull request**

```bash
git add docs/
git commit -m "docs: close TASK-028 slice 3b"
git push -u origin feature/task-028-slice-3b-governance
```

Open the PR against `main` in English, and remember the ruleset: `lint_and_test` must be green and the branch up to date before it can merge.

---

## Notes for whoever executes this

- Tasks 1 to 5 are pure and testable on the host. Task 6 is the risky one: it changes the component that writes RDF, and only the container harness can prove it did not change behaviour. Do not batch it with anything else.
- `isPartial` is not a per-property merge. If a governance write ever deletes a title, the cause is a missing `collectionAction => append` or a property that was cleared without being rewritten.
- The event and its annotations share one microsecond stamp. Second precision restored the wrong state once already (TASK-007).
- A save that changes nothing must write nothing: rewriting identical values reseals the audit trail with a false author and time.
