# TASK-039 Reasons from a CustomVocab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rejecting or discarding a REA takes its reason from a configured CustomVocab, plus an optional
free detail, and falls back to today's free text when no vocabulary resolves.

**Architecture:**
- A value object, `WorkflowReason`, carries the reason text, its data type and the detail, and renders
  the `curation:note` values. `WorkflowService::reject()` and `discard()` take it.
- `ReasonVocabularies` wraps two `VocabEntries`, one for reject and one for discard. It lists the terms
  each form offers and resolves a submitted reason into a `WorkflowReason` or an error code.
- The controller resolves before writing. A shared partial renders a select plus a detail field, or the
  free text field.

**Tech Stack:** PHP 8.4, Omeka S 4.2 (Laminas MVC), CustomVocab module, PHPUnit 11, PSR-12
(`make lint`).

**Spec:** `docs/superpowers/specs/2026-11-23-task-039-reason-vocab-design.md`

## Global Constraints

- Target PHP 8.4. No PHPStan or Psalm. `make lint` (PSR-12) must pass.
- Line coverage of `src/` must stay at 90% or more (`test/check-coverage.php`): every new class gets
  unit tests.
- No custom Doctrine tables (NFR-002). The reason lives in `curation:note`.
- Vocabularies are identified **by id in a setting, never by label** (ADR-0013). The module never creates
  them.
- Every `curation:status` and `curation:note` value is written **private** (`is_public => false`)
  through `WorkflowService::transition()` (StatusGuard, TASK-067).
- Reason and detail are at most **200 characters** each.
- `curation:status` stays a literal. Only `reject` and `discard` use a vocabulary; `unpublish` stays free
  text.
- Setting keys: `oermanager_reject_reason_vocab_id` and `oermanager_discard_reason_vocab_id`.
- New guidance and docs are written in English. The UI copy is Spanish, passed through `$translate`.
- Container harnesses use throwaway data only and remove it at the end (AGENTS.md).
- Commit messages carry no `Co-Authored-By` trailer. Name the model in a plain `Model: …` line of the
  body (project memory).

## Review Focus

1. **A URI-typed or item-set CustomVocab is configured.** Expected: it is treated as not configured (free
   text and hint), never an empty select and never a broken write. → Task 2 (`CustomVocabTermsTest`,
   `ReasonVocabulariesTest`).
2. **A term is submitted with surrounding spaces, or in different letter case.** Expected: spaces are
   trimmed and the reason is accepted; a different case is `reason_invalid`. → Task 2.
3. **A detail is sent while no vocabulary resolves.** Expected: it is dropped, never stored as a second
   literal next to the free reason. → Tasks 2 and 5.
4. **The detail is blank, only spaces, or longer than 200 characters.** Expected: no second value for a
   blank detail; a long one is cut to 200. → Tasks 1 and 3.
5. **The vocabulary has duplicate or blank terms, or its reader throws (CustomVocab disabled, vocabulary
   deleted).** Expected: the select is de-duplicated with no blanks; a throwing reader means free text. →
   Tasks 2 and 5.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Service/Workflow/WorkflowReason.php` (new) | Value object: reason text, data type and detail; the `curation:note` values. |
| `src/Service/Workflow/WorkflowService.php` | `reject()` and `discard()` take a `WorkflowReason`; `transition()` writes its values. |
| `src/Service/Governance/CustomVocabTerms.php` (new) | Invokable reader: a term-typed CustomVocab's terms as `VocabEntries` entries. |
| `src/Service/Workflow/ReasonVocabularies.php` (new) | The two vocabularies: the terms per action, and resolving a submission. |
| `src/Service/GovernanceSettings.php`, `src/Service/ConfigPayload.php`, `src/Form/ConfigForm.php` | The two settings and their fields. |
| `config/module.config.php` | `ReasonVocabularies` factory; the controller gets it. |
| `src/Controller/Admin/IndexController.php` | Resolve before writing; pass the terms to the panel. |
| `view/oer-manager/common/workflow-reason-field.phtml` (new) | The shared reason field. |
| `view/oer-manager/common/workflow-actions.phtml`, `view/oer-manager/admin/index/drawer-details.phtml`, `Module.php` | Use the field and pass the terms. |
| `view/oer-manager/admin/index/my-proposals.phtml` | Show «reason — detail». |
| `test/container/rea-roles-check.php`, `test/container/workflow-check.php` | Container verification; superseded harness signatures. |

---

### Task 1: `WorkflowReason`, and `WorkflowService` writes it

**Files:**
- Create: `src/Service/Workflow/WorkflowReason.php`
- Create: `test/Service/Workflow/WorkflowReasonTest.php`
- Modify: `src/Service/Workflow/WorkflowService.php` (`reject`, `discard`, `unpublish`, `propose`, `reopen`,
  `publish`, `transition`)
- Modify: `test/Service/Workflow/WorkflowServiceTest.php`
- Modify: `src/Controller/Admin/IndexController.php` (the `reject` and `discard` closures only)
- Modify: `test/container/workflow-check.php` (lines calling `->reject(`)

**Interfaces:**
- Produces:
  - `WorkflowReason::literal(string $text): WorkflowReason`
  - `WorkflowReason::term(string $text, string $dataType, string $detail = ''): WorkflowReason`
  - `WorkflowReason::cut(string $text): string`
  - public readonly `string $text`, `?string $dataType` and `string $detail`
  - `noteValues(int $propertyId): list<array{type:string, property_id:int, '@value':string, is_public:false}>`
- Produces: `WorkflowService::reject(ItemRepresentation $item, WorkflowReason $reason, string $contributor): array`,
  and the same for `discard()`. `unpublish(ItemRepresentation, string, string)` is unchanged.

- [ ] **Step 1: Write the failing tests for `WorkflowReason`**

Create `test/Service/Workflow/WorkflowReasonTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Workflow\WorkflowReason;
use PHPUnit\Framework\TestCase;

final class WorkflowReasonTest extends TestCase
{
    public function testALiteralIsTrimmedCutAndHasNoDetail(): void
    {
        $reason = WorkflowReason::literal('  ' . str_repeat('a', 250) . ' ');
        $this->assertSame(str_repeat('a', 200), $reason->text);
        $this->assertNull($reason->dataType);
        $this->assertSame('', $reason->detail);
        $this->assertSame(
            [['type' => 'literal', 'property_id' => 6, '@value' => str_repeat('a', 200), 'is_public' => false]],
            $reason->noteValues(6)
        );
    }

    public function testATermIsWrittenWithItsTypeAndTheDetailAsASecondLiteral(): void
    {
        $reason = WorkflowReason::term(' Duplica un REA existente ', 'customvocab:42', ' ver el REA 12 ');
        $this->assertSame([
            ['type' => 'customvocab:42', 'property_id' => 6, '@value' => 'Duplica un REA existente', 'is_public' => false],
            ['type' => 'literal', 'property_id' => 6, '@value' => 'ver el REA 12', 'is_public' => false],
        ], $reason->noteValues(6));
    }

    public function testABlankDetailAddsNoValueAndALongOneIsCut(): void
    {
        $this->assertCount(1, WorkflowReason::term('Otro motivo', 'customvocab:41', "   \n ")->noteValues(6));
        $long = WorkflowReason::term('Otro motivo', 'customvocab:41', str_repeat('d', 250));
        $this->assertSame(str_repeat('d', 200), $long->detail);
    }

    public function testAnEmptyReasonWritesNothing(): void
    {
        $this->assertSame([], WorkflowReason::literal('   ')->noteValues(6));
    }
}
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter WorkflowReasonTest`
Expected: an error because class `OERManager\Service\Workflow\WorkflowReason` is not found.

- [ ] **Step 3: Implement `WorkflowReason`**

Create `src/Service/Workflow/WorkflowReason.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

/**
 * The reason given when a REA is rejected, discarded or unpublished
 * (TASK-039). It is written to `curation:note` as up to two PRIVATE values:
 * the reason (a CustomVocab term typed `customvocab:N`, or a literal when no
 * vocabulary resolves) and an optional detail literal. Each is cut to 200
 * characters.
 */
final class WorkflowReason
{
    public const MAX_LENGTH = 200;

    private function __construct(
        public readonly string $text,
        public readonly ?string $dataType,
        public readonly string $detail
    ) {
    }

    /** Free text, with no vocabulary: trimmed, cut, and no detail. */
    public static function literal(string $text): self
    {
        return new self(self::cut($text), null, '');
    }

    /** A term of the vocabulary whose data type is `$dataType`, with an optional detail. */
    public static function term(string $text, string $dataType, string $detail = ''): self
    {
        return new self(self::cut($text), $dataType, self::cut($detail));
    }

    public static function cut(string $text): string
    {
        return trim(mb_substr(trim($text), 0, self::MAX_LENGTH));
    }

    /** @return list<array{type:string, property_id:int, '@value':string, is_public:false}> */
    public function noteValues(int $propertyId): array
    {
        if ('' === $this->text) {
            return [];
        }
        $values = [self::value($this->dataType ?? 'literal', $propertyId, $this->text)];
        if ('' !== $this->detail) {
            $values[] = self::value('literal', $propertyId, $this->detail);
        }
        return $values;
    }

    /** @return array{type:string, property_id:int, '@value':string, is_public:false} */
    private static function value(string $type, int $propertyId, string $text): array
    {
        return ['type' => $type, 'property_id' => $propertyId, '@value' => $text, 'is_public' => false];
    }
}
```

- [ ] **Step 4: Run them to confirm they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter WorkflowReasonTest`
Expected: OK (4 tests).

- [ ] **Step 5: Write the failing `WorkflowService` test**

In `test/Service/Workflow/WorkflowServiceTest.php`, add
`use OERManager\Service\Workflow\WorkflowReason;` and wrap every string reason passed to `reject()` or
`discard()` in `WorkflowReason::literal(...)`. Today these are:

- `reject($this->rea('Propuesto'), ' Fix metadata ', 'Cu')`
- `discard($this->rea('Rechazado'), 'Duplicate', 'Cu')`
- `reject($this->rea(' '), 'x', 'Cu')`

Find any others with `grep -n "reject(\|discard(" test/Service/Workflow/WorkflowServiceTest.php`. Leave
`unpublish()` calls as they are. Then add this test:

```php
    public function testAVocabularyReasonIsWrittenAsItsTermWithTheOptionalDetail(): void
    {
        $service = $this->service();
        $service->reject(
            $this->rea('Propuesto'),
            WorkflowReason::term('Licencia ausente o incompatible', 'customvocab:41', ' falta la licencia del vídeo 2 '),
            'Cu'
        );
        $service->discard($this->rea('Rechazado'), WorkflowReason::term('Duplica un REA existente', 'customvocab:42'), 'Cu');
        $service->propose($this->rea('Rechazado'), 'Ana');

        $this->assertSame([
            ['type' => 'customvocab:41', 'property_id' => 6, '@value' => 'Licencia ausente o incompatible', 'is_public' => false],
            ['type' => 'literal', 'property_id' => 6, '@value' => 'falta la licencia del vídeo 2', 'is_public' => false],
        ], $this->writes[0][WorkflowStatus::NOTE_TERM]);
        $this->assertSame(
            [['type' => 'customvocab:42', 'property_id' => 6, '@value' => 'Duplica un REA existente', 'is_public' => false]],
            $this->writes[1][WorkflowStatus::NOTE_TERM]
        );
        // The whole note is cleared each time, so the reason and its detail go together.
        $this->assertSame([5, 6], $this->writes[0]['clear_property_values']);
        $this->assertSame([], $this->writes[2][WorkflowStatus::NOTE_TERM]);
    }
```

- [ ] **Step 6: Run it to confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter WorkflowServiceTest`
Expected: a `TypeError`, because `reject()` takes a string.

- [ ] **Step 7: Change `WorkflowService`**

In `src/Service/Workflow/WorkflowService.php`:

```php
    /** @return array{updated:bool, status?:string, error?:string} */
    public function reject(ItemRepresentation $item, WorkflowReason $reason, string $contributor): array
    {
        if (!WorkflowStatus::canReject($this->statusOf($item))) {
            return self::invalid();
        }
        return $this->transition($item, WorkflowStatus::REJECTED, $reason, null, $contributor);
    }
```

- `discard()`: take `WorkflowReason $reason` and pass `$reason` (instead of `trim($reason)`) to
  `transition()`.
- `unpublish()`: keep `string $reason` and pass `WorkflowReason::literal($reason)`.
- `propose()`, `reopen()` and `publish()`: pass `null` where they pass `''` today.
- `transition()`:
  - its third parameter becomes `?WorkflowReason $note`;
  - the docblock line becomes `@param WorkflowReason|null $note null = clear curation:note`;
  - the note line becomes:

```php
            $data[WorkflowStatus::NOTE_TERM] = null === $note ? [] : $note->noteValues($notePropertyId);
```

- [ ] **Step 8: Adapt the controller closures and the superseded harness**

In `src/Controller/Admin/IndexController.php`:
- add `use OERManager\Service\Workflow\WorkflowReason;`;
- in `rejectProposalAction()` and `discardAction()`, pass `WorkflowReason::literal($reason)` instead of
  `$reason`. Task 3 replaces this with the vocabulary.

In `test/container/workflow-check.php`, wrap the two `->reject($item, '…', 'workflow-check')` reasons in
`\OERManager\Service\Workflow\WorkflowReason::literal('…')`.

- [ ] **Step 9: Run the workflow and controller tests**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'Workflow|IndexControllerTest'`
Expected: OK.

- [ ] **Step 10: Commit**

```bash
git add src/Service/Workflow/WorkflowReason.php src/Service/Workflow/WorkflowService.php \
  src/Controller/Admin/IndexController.php test/Service/Workflow test/container/workflow-check.php
git commit -m "feat: a workflow reason can be a CustomVocab term with an optional detail (TASK-039)" \
  -m "Model: <the model that did this task>"
```

---

### Task 2: settings, reader and `ReasonVocabularies`

**Files:**
- Create: `src/Service/Governance/CustomVocabTerms.php`, `test/Service/Governance/CustomVocabTermsTest.php`
- Create: `src/Service/Workflow/ReasonVocabularies.php`, `test/Service/Workflow/ReasonVocabulariesTest.php`
- Modify: `src/Service/GovernanceSettings.php`, `src/Service/ConfigPayload.php`, `src/Form/ConfigForm.php`
- Modify: `test/Service/ConfigPayloadTest.php`
- Modify: `config/module.config.php` (service factories, next to `VocabEntries\Publisher`)

**Interfaces:**
- Consumes: `WorkflowReason` (Task 1); `VocabEntries::__construct(?int $vocabId, callable $reader)`, which
  provides `entries()` and `dataType()`.
- Produces:
  - `ReasonVocabularies::REJECT = 'reject'`
  - `ReasonVocabularies::DISCARD = 'discard'`
  - `terms(string $action): ?array`, a `list<string>`, or null for free text
  - `resolve(string $action, string $reason, string $detail): array`, which returns
    `['reason' => WorkflowReason]` or `['error' => 'reason_required'|'reason_invalid']`
- Produces: `GovernanceSettings::REJECT_REASON_VOCAB_ID` and `GovernanceSettings::DISCARD_REASON_VOCAB_ID`.

- [ ] **Step 1: Write the failing tests**

Create `test/Service/Governance/CustomVocabTermsTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CustomVocabTerms;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

final class CustomVocabTermsTest extends TestCase
{
    private function reader(?string $type, ?array $terms): CustomVocabTerms
    {
        $vocab = new class ($type, $terms) {
            public function __construct(private ?string $type, private ?array $terms)
            {
            }

            public function type(): ?string
            {
                return $this->type;
            }

            public function listTerms(): ?array
            {
                return null === $this->terms ? null : array_combine($this->terms, $this->terms);
            }
        };
        $api = $this->createMock(Manager::class);
        $api->method('read')->with('custom_vocabs', 41)->willReturn(new Response($vocab));
        return new CustomVocabTerms($api);
    }

    public function testATermVocabularyGivesItsTerms(): void
    {
        $this->assertSame(
            [['value' => 'Otro motivo'], ['value' => 'Duplica un REA existente']],
            ($this->reader('literal', ['Otro motivo', 'Duplica un REA existente']))(41)
        );
    }

    public function testUriAndItemSetVocabulariesGiveNoTerms(): void
    {
        $this->assertSame([], ($this->reader('uri', null))(41));
        $this->assertSame([], ($this->reader('resource', null))(41));
        $this->assertSame([], ($this->reader('literal', null))(41));
    }
}
```

Create `test/Service/Workflow/ReasonVocabulariesTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Workflow;

use OERManager\Service\Governance\VocabEntries;
use OERManager\Service\Workflow\ReasonVocabularies;
use PHPUnit\Framework\TestCase;

final class ReasonVocabulariesTest extends TestCase
{
    private function vocabularies(?array $reject, ?array $discard = null): ReasonVocabularies
    {
        $entries = static fn (?array $terms): VocabEntries => new VocabEntries(
            null === $terms ? null : 41,
            static fn (int $id): array => $terms ?? []
        );
        return new ReasonVocabularies($entries($reject), $entries($discard));
    }

    public function testWithoutAVocabularyTheReasonIsFreeTextAndTheDetailIsDropped(): void
    {
        $vocabularies = $this->vocabularies(null);
        $this->assertNull($vocabularies->terms(ReasonVocabularies::REJECT));
        $reason = $vocabularies->resolve(ReasonVocabularies::REJECT, ' Falta la licencia ', 'detalle')['reason'];
        $this->assertSame('Falta la licencia', $reason->text);
        $this->assertNull($reason->dataType);
        $this->assertSame('', $reason->detail);
    }

    public function testAnEmptyReasonIsRequiredWithOrWithoutAVocabulary(): void
    {
        $this->assertSame(['error' => 'reason_required'], $this->vocabularies(null)->resolve('reject', '  ', 'x'));
        $this->assertSame(
            ['error' => 'reason_required'],
            $this->vocabularies([['value' => 'Otro motivo']])->resolve('reject', '', 'x')
        );
    }

    public function testAVocabularyOffersItsTermsWithoutBlanksOrDuplicates(): void
    {
        $vocabularies = $this->vocabularies([['value' => 'Otro motivo'], ['value' => ' '], ['value' => 'Otro motivo'],
            ['value' => 'Accesibilidad insuficiente']]);
        $this->assertSame(['Otro motivo', 'Accesibilidad insuficiente'], $vocabularies->terms('reject'));
    }

    public function testOnlyATermIsAcceptedTrimmedButCaseSensitive(): void
    {
        $vocabularies = $this->vocabularies([['value' => 'Otro motivo']], [['value' => 'Duplica un REA existente']]);
        $this->assertSame(['error' => 'reason_invalid'], $vocabularies->resolve('reject', 'texto libre', ''));
        $this->assertSame(['error' => 'reason_invalid'], $vocabularies->resolve('reject', 'otro motivo', ''));
        // Each action has its own list.
        $this->assertSame(['error' => 'reason_invalid'], $vocabularies->resolve('discard', 'Otro motivo', ''));
        $reason = $vocabularies->resolve('reject', ' Otro motivo ', ' el vídeo 2 ')['reason'];
        $this->assertSame('Otro motivo', $reason->text);
        $this->assertSame('customvocab:41', $reason->dataType);
        $this->assertSame('el vídeo 2', $reason->detail);
    }

    public function testUriEntriesAnEmptyListOrAFailingReaderMeanFreeText(): void
    {
        $this->assertNull($this->vocabularies([['uri' => 'https://example.org/x']])->terms('reject'));
        $this->assertNull($this->vocabularies([])->terms('reject'));
        $failing = new ReasonVocabularies(
            new VocabEntries(41, static function (): array {
                throw new \RuntimeException('CustomVocab is not active');
            }),
            new VocabEntries(null, static fn (): array => [])
        );
        $this->assertNull($failing->terms('reject'));
        $this->assertNull($failing->terms('unpublish'));
        $this->assertNull($failing->resolve('unpublish', 'Licencia dudosa', '')['reason']->dataType);
    }
}
```

In `test/Service/ConfigPayloadTest.php`, in `testReadCoversEveryFieldOfTheForm`, add
`GovernanceSettings::REJECT_REASON_VOCAB_ID` and `GovernanceSettings::DISCARD_REASON_VOCAB_ID` right after
`GovernanceSettings::PUBLISHER_VOCAB_ID`. Then add:

```php
    public function testTheReasonVocabulariesAreSavedAsIds(): void
    {
        $settings = ConfigPayload::write([
            GovernanceSettings::REJECT_REASON_VOCAB_ID => '12',
            GovernanceSettings::DISCARD_REASON_VOCAB_ID => 'no soy un id',
        ]);
        $this->assertSame(12, $settings[GovernanceSettings::REJECT_REASON_VOCAB_ID]);
        $this->assertNull($settings[GovernanceSettings::DISCARD_REASON_VOCAB_ID]);
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'CustomVocabTermsTest|ReasonVocabulariesTest|ConfigPayloadTest'`
Expected: errors for the missing classes and constants.

- [ ] **Step 3: Add the settings and form fields**

In `src/Service/GovernanceSettings.php`, after `PUBLISHER_VOCAB_ID`:

```php
    /**
     * CustomVocab of the reasons to reject a proposal (TASK-039). Created by
     * hand, never by the module; empty means the reason is free text.
     */
    public const REJECT_REASON_VOCAB_ID = 'oermanager_reject_reason_vocab_id';

    /** CustomVocab of the reasons to discard a REA (TASK-039, ADR-0023). Created by hand. */
    public const DISCARD_REASON_VOCAB_ID = 'oermanager_discard_reason_vocab_id';
```

In `src/Service/ConfigPayload.php`, add both to `GOVERNANCE_ID_SETTINGS` right after
`GovernanceSettings::PUBLISHER_VOCAB_ID`.

In `src/Form/ConfigForm.php`, `addGovernanceFields()`, add these two fields right after the
`PUBLISHER_VOCAB_ID` field:

```php
        $this->add([
            'name' => GovernanceSettings::REJECT_REASON_VOCAB_ID,
            'type' => 'Number',
            'options' => [
                'label' => 'CustomVocab de motivos de rechazo', // @translate
                'info' => 'ID del CustomVocab de términos con los motivos para rechazar una propuesta. '
                    . 'El módulo NO lo crea. Si se deja vacío, el motivo se escribe a mano.', // @translate
            ],
            'attributes' => [
                'id' => GovernanceSettings::REJECT_REASON_VOCAB_ID,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->add([
            'name' => GovernanceSettings::DISCARD_REASON_VOCAB_ID,
            'type' => 'Number',
            'options' => [
                'label' => 'CustomVocab de motivos de descarte', // @translate
                'info' => 'ID del CustomVocab de términos con los motivos para descartar un REA. '
                    . 'El módulo NO lo crea. Si se deja vacío, el motivo se escribe a mano.', // @translate
            ],
            'attributes' => [
                'id' => GovernanceSettings::DISCARD_REASON_VOCAB_ID,
                'min' => 1,
                'step' => 1,
            ],
        ]);
```

If the form has an input filter that lists the governance ids as optional, add both keys there as well.
Check with `grep -n PUBLISHER_VOCAB_ID src/Form/ConfigForm.php`.

- [ ] **Step 4: Implement the reader and `ReasonVocabularies`**

Create `src/Service/Governance/CustomVocabTerms.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

use Omeka\Api\Manager as ApiManager;

/**
 * Reader for VocabEntries over a TERM-typed CustomVocab (TASK-039): each
 * term as `['value' => term]`. A URI or item-set vocabulary yields no
 * entries, because a reason is a plain term; such a vocabulary therefore
 * counts as not configured. A missing vocabulary, or an inactive CustomVocab,
 * throws, and VocabEntries turns that into «unresolved».
 */
final class CustomVocabTerms
{
    public function __construct(private readonly ApiManager $api)
    {
    }

    /** @return list<array{value:string}> */
    public function __invoke(int $id): array
    {
        $vocab = $this->api->read('custom_vocabs', $id)->getContent();
        if ('literal' !== $vocab->type()) {
            return [];
        }
        $entries = [];
        foreach (array_values($vocab->listTerms() ?? []) as $term) {
            $entries[] = ['value' => (string) $term];
        }
        return $entries;
    }
}
```

Create `src/Service/Workflow/ReasonVocabularies.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service\Workflow;

use OERManager\Service\Governance\VocabEntries;

/**
 * The reasons to reject and to discard come from their own CustomVocab
 * (TASK-039). Without a usable vocabulary (setting empty, vocabulary missing
 * or not term-typed, CustomVocab inactive, no terms), the reason is free
 * text, as before; the flow is never blocked. Terms are matched exactly,
 * after trimming, and never by id or position: the vocabulary is the
 * administrator's.
 */
final class ReasonVocabularies
{
    public const REJECT = 'reject';
    public const DISCARD = 'discard';

    public function __construct(
        private readonly VocabEntries $reject,
        private readonly VocabEntries $discard
    ) {
    }

    /** @return list<string>|null the terms the form offers; null = free text */
    public function terms(string $action): ?array
    {
        $vocab = $this->vocab($action);
        if (null === $vocab || null === $vocab->dataType()) {
            return null;
        }
        $terms = [];
        foreach ($vocab->entries() as $entry) {
            if (isset($entry['uri'])) {
                continue;
            }
            $term = trim((string) ($entry['value'] ?? ''));
            if ('' !== $term && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }
        return [] === $terms ? null : $terms;
    }

    /**
     * @return array{reason: WorkflowReason}|array{error: string} the error is
     *         `reason_required` or `reason_invalid`
     */
    public function resolve(string $action, string $reason, string $detail): array
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return ['error' => 'reason_required'];
        }
        $terms = $this->terms($action);
        if (null === $terms) {
            return ['reason' => WorkflowReason::literal($reason)];
        }
        if (!in_array($reason, $terms, true)) {
            return ['error' => 'reason_invalid'];
        }
        return ['reason' => WorkflowReason::term($reason, (string) $this->vocab($action)?->dataType(), $detail)];
    }

    private function vocab(string $action): ?VocabEntries
    {
        return match ($action) {
            self::REJECT => $this->reject,
            self::DISCARD => $this->discard,
            default => null,
        };
    }
}
```

In `config/module.config.php`, `service_manager` → `factories`, right after the
`'OERManager\Service\Governance\VocabEntries\Publisher'` factory:

```php
            // TASK-039: the reasons to reject and to discard, each from its own
            // term CustomVocab, read by the id in its setting. Soft dependency:
            // without a usable vocabulary the reason is free text.
            Service\Workflow\ReasonVocabularies::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $reader = new Service\Governance\CustomVocabTerms($container->get('Omeka\ApiManager'));
                $vocab = static fn (string $key) => new Service\Governance\VocabEntries(
                    Service\GovernanceSettings::parseId($settings->get($key)),
                    $reader
                );
                return new Service\Workflow\ReasonVocabularies(
                    $vocab(Service\GovernanceSettings::REJECT_REASON_VOCAB_ID),
                    $vocab(Service\GovernanceSettings::DISCARD_REASON_VOCAB_ID)
                );
            },
```

- [ ] **Step 5: Run the tests to confirm they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'CustomVocabTermsTest|ReasonVocabulariesTest|ConfigPayloadTest|ModuleConfigTest|ModuleRuntimeTest'`
Expected: OK.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Governance/CustomVocabTerms.php src/Service/Workflow/ReasonVocabularies.php \
  src/Service/GovernanceSettings.php src/Service/ConfigPayload.php src/Form/ConfigForm.php \
  config/module.config.php test/Service
git commit -m "feat: reasons to reject and to discard are read from their own CustomVocab (TASK-039)" \
  -m "Model: <the model that did this task>"
```

---

### Task 3: the controller resolves the reason and passes the terms

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`: constructor, the five `workflowTransition` closures,
  `workflowTransition()`, `workflowGuard()`, `publishProposalAction()` and `drawerDetailsAction()`
- Modify: `config/module.config.php` (the `IndexController` factory)
- Modify: `test/Controller/IndexControllerTest.php`

**Interfaces:**
- Consumes: `ReasonVocabularies::resolve()`, `terms()`, `REJECT` and `DISCARD`, and `WorkflowReason`
  (Tasks 1 and 2).
- Produces: the drawer view variables `rejectReasons` and `discardReasons` (`list<string>|null`); the JSON
  error code `reason_invalid`.

- [ ] **Step 1: Write the failing tests**

In `test/Controller/IndexControllerTest.php`:

1. Add properties:

```php
    /** @var list<array<string,string>> */
    private array $rejectTerms = [];
    /** @var list<array<string,string>> */
    private array $discardTerms = [];
    /** @var array<string,mixed> */
    private array $lastUpdate = [];
```

2. Add uses for `OERManager\Service\Governance\VocabEntries` and
   `OERManager\Service\Workflow\ReasonVocabularies`.
3. Make the `update` mock record its data. Change its callback to
   `function ($resource, $id, $data = []) { … $this->lastUpdate = $data; … }`, keeping its current body.
4. Add to `$real` in `setUp()`:

```php
            'reasonVocabularies' => new ReasonVocabularies(
                new VocabEntries(41, fn (int $id): array => $this->rejectTerms),
                new VocabEntries(42, fn (int $id): array => $this->discardTerms)
            ),
```

5. Add the tests:

```php
    /** TASK-039: reject and discard take their reason from their own vocabulary. */
    public function testRejectAndDiscardTakeAReasonFromTheirVocabulary(): void
    {
        $this->rejectTerms = [['value' => 'Licencia ausente o incompatible']];
        $this->discardTerms = [['value' => 'Duplica un REA existente']];
        $this->request->ajax = true;

        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->params->post['reason'] = 'texto libre';
        $this->assertSame('reason_invalid', $this->data('rejectProposal')['error']);
        $this->params->post['reason'] = '';
        $this->assertSame('reason_required', $this->data('rejectProposal')['error']);
        $this->assertSame(0, $this->updates, 'nothing is written for an invalid reason');

        $this->params->post['reason'] = ' Licencia ausente o incompatible ';
        $this->params->post['detail'] = str_repeat('d', 250);
        $this->assertTrue($this->data('rejectProposal')['updated']);
        $note = $this->lastUpdate[WorkflowStatus::NOTE_TERM];
        $this->assertSame(['customvocab:41', 'literal'], array_column($note, 'type'));
        $this->assertSame(['Licencia ausente o incompatible', str_repeat('d', 200)], array_column($note, '@value'));

        $this->workflowStatus = WorkflowStatus::REJECTED;
        $this->params->post['detail'] = '';
        $this->assertSame('reason_invalid', $this->data('discard')['error'], 'each action has its own list');
        $this->params->post['reason'] = 'Duplica un REA existente';
        $this->assertTrue($this->data('discard')['updated']);
        $this->assertSame(['customvocab:42'], array_column($this->lastUpdate[WorkflowStatus::NOTE_TERM], 'type'));

        // «Despublicar» stays free text.
        $this->workflowStatus = WorkflowStatus::PUBLISHED;
        $this->params->post['reason'] = 'Licencia dudosa';
        $this->assertTrue($this->data('unpublish')['updated']);
        $this->assertSame(['literal'], array_column($this->lastUpdate[WorkflowStatus::NOTE_TERM], 'type'));
    }

    public function testTheDrawerOffersTheTermsOfEachVocabulary(): void
    {
        $this->params->query = ['id' => 7];
        $this->workflowStatus = WorkflowStatus::PROPOSED;
        $this->dependencies['itemPanelData']->method('forItem')->willReturn([]);
        $this->dependencies['acl']->method('userIsAllowed')->willReturn(true);
        $this->rejectTerms = [['value' => 'Otro motivo']];
        $view = $this->controller->drawerDetailsAction();
        $this->assertSame(['Otro motivo'], $view->getVariable('rejectReasons'));
        $this->assertNull($view->getVariable('discardReasons'), 'no terms: free text');
    }
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IndexControllerTest`
Expected: the new tests fail, because `reason_invalid` is never returned and `rejectReasons` is not set.

- [ ] **Step 3: Implement**

In `src/Controller/Admin/IndexController.php`:
- Add `use OERManager\Service\Workflow\ReasonVocabularies;`. `WorkflowReason` is already imported in
  Task 1.
- Add `ReasonVocabularies $reasonVocabularies` as the **last** constructor parameter. Store it in a new
  property, `private ReasonVocabularies $reasonVocabularies;`.
- In every `workflowTransition` closure (propose, reject, discard, unpublish, reopen), change the
  parameter type `string $reason` to `WorkflowReason $reason`:
  - reject: `->reject($item, $reason, $who)`;
  - discard: `->discard($item, $reason, $who)`;
  - unpublish: `->unpublish($item, $reason->text, $who)`.
- Replace `workflowTransition()` and `workflowGuard()`:

```php
    private function workflowTransition(string $name, bool $reasonRequired, callable $apply)
    {
        $guard = $this->workflowGuard();
        if (!is_array($guard) || !isset($guard['request'])) {
            return $guard;
        }
        ['id' => $id, 'ajax' => $ajax, 'reason' => $reason, 'detail' => $detail] = $guard['request'];
        $resolved = $this->workflowReason($name, $reasonRequired, $reason, $detail);
        if (isset($resolved['error'])) {
            $message = 'reason_invalid' === $resolved['error']
                ? 'Elige un motivo de la lista.' // @translate
                : 'Debes indicar un motivo.'; // @translate
            return $this->workflowFailure($ajax, $id, $resolved['error'], $message);
        }
        $item = $this->workflowItem($id);
        if (null === $item) {
            return $this->workflowNotFound($ajax);
        }
        return $this->workflowApply(
            $name,
            $ajax,
            $id,
            fn () => $apply($item, $resolved['reason'], $this->contributor())
        );
    }

    /**
     * TASK-039: reject and discard resolve their reason against their own
     * vocabulary (free text without one); the other transitions keep a free
     * reason, required only where it was.
     *
     * @return array{reason: WorkflowReason}|array{error: string}
     */
    private function workflowReason(string $name, bool $required, string $reason, string $detail): array
    {
        if (in_array($name, [ReasonVocabularies::REJECT, ReasonVocabularies::DISCARD], true)) {
            return $this->reasonVocabularies->resolve($name, $reason, $detail);
        }
        $literal = WorkflowReason::literal($reason);
        return $required && '' === $literal->text ? ['error' => 'reason_required'] : ['reason' => $literal];
    }

    /**
     * POST and CSRF. Returns `['request' => [id, ajax, reason, detail]]`, raw,
     * or the error response (redirect or JsonModel), which the caller returns
     * as is. The reason is checked by workflowReason().
     *
     * @return array{request: array{id:int, ajax:bool, reason:string, detail:string}}|mixed
     */
    private function workflowGuard()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }
        $ajax = (bool) $this->getRequest()->isXmlHttpRequest();
        $id = (int) $this->params()->fromPost('id');
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            $message = 'Token de seguridad inválido. Vuelve a intentarlo.'; // @translate
            return $this->workflowFailure($ajax, $id, 'csrf', $message);
        }
        return ['request' => [
            'id' => $id,
            'ajax' => $ajax,
            'reason' => (string) $this->params()->fromPost('reason', ''),
            'detail' => (string) $this->params()->fromPost('detail', ''),
        ]];
    }
```

- In `publishProposalAction()`, change `$this->workflowGuard('publish', false)` to `$this->workflowGuard()`.
- In `drawerDetailsAction()`:
  - in the early-return `setVariables([...])` for a missing item, add `'rejectReasons' => null` and
    `'discardReasons' => null`;
  - in the final `setVariables([...])`, add
    `'rejectReasons' => $this->reasonVocabularies->terms(ReasonVocabularies::REJECT)` and
    `'discardReasons' => $this->reasonVocabularies->terms(ReasonVocabularies::DISCARD)`.
- Update the `rejectProposalAction()` docblock: «El motivo sale del vocabulario de rechazo (TASK-039);
  texto libre si no hay ninguno.»

In `config/module.config.php`, in the `IndexController` factory, add as the last argument after
`$container->get(Service\ReaSite::class)`:

```php
                    // TASK-039: reasons to reject and to discard.
                    $container->get(Service\Workflow\ReasonVocabularies::class)
```

- [ ] **Step 4: Run the tests to confirm they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter IndexControllerTest`
Expected: OK, including `testWorkflowActionsReturnAjaxAndHtmlOutcomes`. That test posts `reason=Revise`
with empty vocabularies (free text) and an empty reason (`reason_required`).

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/IndexController.php config/module.config.php test/Controller/IndexControllerTest.php
git commit -m "feat: reject and discard validate their reason against their vocabulary (TASK-039)" \
  -m "Model: <the model that did this task>"
```

---

### Task 4: the forms and «Mis propuestas»

**Files:**
- Create: `view/oer-manager/common/workflow-reason-field.phtml`
- Modify: `view/oer-manager/common/workflow-actions.phtml`, `view/oer-manager/admin/index/drawer-details.phtml`,
  `view/oer-manager/admin/index/my-proposals.phtml`, `Module.php` (`addWorkflowActions`)
- Modify: `test/stubs/Laminas/View/Renderer/PhpRenderer.php`, `test/ModuleRuntimeTest.php`
- Modify: `asset/css/oer-workflow-actions.css`

**Interfaces:**
- Consumes: `ReasonVocabularies::terms()` (Task 2); the drawer variables `rejectReasons` and
  `discardReasons` (Task 3).
- Produces: the partial `oer-manager/common/workflow-reason-field`, with variables `fieldId`, `label`,
  `terms` (`list<string>|null`), `controlled` (bool) and `describedBy` (string, optional).

- [ ] **Step 1: Write the failing test**

The test stub `PhpRenderer::partial()` does not keep its arguments. In
`test/stubs/Laminas/View/Renderer/PhpRenderer.php`, add `public array $partials = [];` and make
`partial()` record each call before returning:

```php
    public array $partials = [];
    public function partial($name, $args = [])
    {
        $this->partials[] = [$name, $args];
        return $name . (isset($args['badge']) ? '[' . $args['badge'] . ']' : '');
    }
```

In `test/ModuleRuntimeTest.php`, `testWorkflowActionsRenderOnlyEditableLearningResources`, add to the
`$this->module([...])` map:

```php
            \OERManager\Service\Workflow\ReasonVocabularies::class => new \OERManager\Service\Workflow\ReasonVocabularies(
                new \OERManager\Service\Governance\VocabEntries(41, static fn (): array => [['value' => 'Otro motivo']]),
                new \OERManager\Service\Governance\VocabEntries(null, static fn (): array => [])
            ),
```

and after the existing `$this->assertSame('oer-manager/common/workflow-actions', ob_get_clean());` add:

```php
        $args = end($view->partials)[1];
        $this->assertSame(['Otro motivo'], $args['rejectReasons']);
        $this->assertNull($args['discardReasons']);
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter testWorkflowActionsRenderOnlyEditableLearningResources`
Expected: FAIL with an undefined array key `rejectReasons`.

- [ ] **Step 3: Implement the shared field and use it**

Create `view/oer-manager/common/workflow-reason-field.phtml`:

```php
<?php
/**
 * The reason field of a workflow form (TASK-039), shared by the item page
 * and the detail panel. With a vocabulary: a required select of its terms
 * and an optional detail. Without one: today's free text, and, for the
 * actions that take a vocabulary, a hint that none is configured.
 *
 * @var \Laminas\View\Renderer\PhpRenderer $this
 * @var string $fieldId id of the reason control (the label's `for`)
 * @var string $label
 * @var list<string>|null $terms null = free text
 * @var bool $controlled the action uses a vocabulary (reject, discard)
 * @var string|null $describedBy id of a help text, if any
 */
$translate = $this->plugin('translate');
$escape = $this->plugin('escapeHtml');
$described = empty($describedBy) ? '' : ' aria-describedby="' . $escape($describedBy) . '"';
?>
<label for="<?php echo $escape($fieldId); ?>"><?php echo $escape($label); ?></label>
<?php if (null !== $terms): ?>
<select id="<?php echo $escape($fieldId); ?>" name="reason" required<?php echo $described; ?>>
    <option value=""><?php echo $escape($translate('Elige un motivo…')); ?></option>
    <?php foreach ($terms as $term): ?>
    <option value="<?php echo $escape($term); ?>"><?php echo $escape($term); ?></option>
    <?php endforeach; ?>
</select>
<label for="<?php echo $escape($fieldId); ?>-detail"><?php echo $escape($translate('Concreta (opcional)')); ?></label>
<input type="text" id="<?php echo $escape($fieldId); ?>-detail" name="detail" maxlength="200">
<?php else: ?>
<input type="text" id="<?php echo $escape($fieldId); ?>" name="reason" required maxlength="200"<?php echo $described; ?>>
<?php if ($controlled): ?>
<p class="oer-workflow-reason-hint"><?php echo $escape($translate('Lista de motivos no configurada: escribe el motivo.')); ?></p>
<?php endif; ?>
<?php endif; ?>
```

In `view/oer-manager/common/workflow-actions.phtml`:
- add `@var list<string>|null $rejectReasons` and `@var list<string>|null $discardReasons` to the
  docblock;
- in `$reasonActions`, add `'terms' => $rejectReasons ?? null, 'controlled' => true` to the reject entry,
  `'terms' => $discardReasons ?? null, 'controlled' => true` to the discard entry, and
  `'terms' => null, 'controlled' => false` to the unpublish entry;
- replace the `<label …>` and `<input type="text" … name="reason" …>` lines inside the form with:

```php
            <?php echo $this->partial('oer-manager/common/workflow-reason-field', [
                'fieldId' => $fieldId,
                'label' => $action['label'],
                'terms' => $action['terms'],
                'controlled' => $action['controlled'],
                'describedBy' => $fieldId . '-help',
            ]); ?>
```

In `Module.php`, `addWorkflowActions()`: after the early `return` (when no button is available) and
before `echo $view->partial('oer-manager/common/workflow-actions', …)`, add
`$reasons = $services->get(Service\Workflow\ReasonVocabularies::class);`. Add to the partial's array:

```php
            'rejectReasons' => $reasons->terms(Service\Workflow\ReasonVocabularies::REJECT),
            'discardReasons' => $reasons->terms(Service\Workflow\ReasonVocabularies::DISCARD),
```

In `view/oer-manager/admin/index/drawer-details.phtml`:
- add `rejectReasons` and `discardReasons` to the `@var` docblock;
- in `$reasonActions`, append a sixth element to each entry: `$rejectReasons ?? null` for reject,
  `$discardReasons ?? null` for discard, and `false` for unpublish (the sentinel for «not controlled»);
- in the form loop, destructure as
  `[$show, $url, , $reasonLabel, $submit, $terms]`;
- replace the `<label for="oer-workflow-reason-…-text">…</label>` and the
  `<input type="text" name="reason" …>` with:

```php
            <?php echo $this->partial('oer-manager/common/workflow-reason-field', [
                'fieldId' => 'oer-workflow-reason-' . $key . '-text',
                'label' => $reasonLabel,
                'terms' => false === $terms ? null : $terms,
                'controlled' => false !== $terms,
            ]); ?>
```

In `view/oer-manager/admin/index/my-proposals.phtml`, replace `$note = $item->value(WorkflowStatus::NOTE_TERM);`
with:

```php
        // TASK-039: the reason and, if any, its detail.
        $notes = array_map(
            static fn ($value): string => (string) $value->value(),
            $item->value(WorkflowStatus::NOTE_TERM, ['all' => true, 'default' => []])
        );
```

and its cell with `<td><?php echo $escape(implode(' — ', $notes)); ?></td>`.

Add the hint style next to the existing reason styles. Check with
`grep -rn "oer-workflow-reason-help\|oer-drawer-workflow-form" asset/css`. In `asset/css/oer-workflow-actions.css`,
and in the stylesheet that styles `.oer-drawer-workflow-form` if it is a different one, add:

```css
.oer-workflow-reason-hint {
    margin: 0.25em 0 0;
    font-size: 0.9em;
}
```

- [ ] **Step 4: Run the tests and lint**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ModuleRuntimeTest && make lint`
Expected: OK, lint clean. Then `make test` (the whole suite, coverage gate included) passes.

- [ ] **Step 5: Commit**

```bash
git add view Module.php asset/css test/ModuleRuntimeTest.php test/stubs
git commit -m "feat: the reject and discard forms offer the vocabulary's reasons and an optional detail (TASK-039)" \
  -m "Model: <the model that did this task>"
```

---

### Task 5: container verification with throwaway vocabularies

**Files:**
- Modify: `test/container/rea-roles-check.php`

**Interfaces:**
- Consumes: the settings `GovernanceSettings::REJECT_REASON_VOCAB_ID` and `DISCARD_REASON_VOCAB_ID`; the
  HTTP endpoints `reject-proposal`, `discard`, `reopen` and `propose`; `drawer-details`; `my-proposals`.
  Within the harness: `$p`, `$c` (proposer and curator browsers), `->post(action, fields)`,
  `->request('GET', path)` (which returns `[status, body]`), `->csrf(path)`, and `$make`, `$fix`,
  `$copy`, `$read`, `$as`, `$created`, `$cCsrf`, `$suffix` and `TAG`. All of them are defined in the
  current harness.

- [ ] **Step 1: Pin the starting state**

In the Setup section, right after `$settings->set(ReaSite::SETTING, $reaSiteId);`, add:

```php
$reasonSettings = [
    \OERManager\Service\GovernanceSettings::REJECT_REASON_VOCAB_ID,
    \OERManager\Service\GovernanceSettings::DISCARD_REASON_VOCAB_ID,
];
$previousReasonVocabs = [];
foreach ($reasonSettings as $key) {
    $previousReasonVocabs[$key] = $settings->get($key);
    // The main flow below exercises the free-text fallback (TASK-039).
    $settings->set($key, null);
}
```

Add `'vocabs' => []` to the `$created` array.

- [ ] **Step 2: Add the section after the «Flow» section**

Add it right before `section('Hand-made status changes are rejected (security review)');`:

```php
    // ------------------------------------------------------------ TASK-039
    section('Reasons from the vocabularies (TASK-039)');
    $as(null);
    $rejectVocab = (int) $api->create('custom_vocabs', [
        'o:label' => TAG . ' motivos de rechazo ' . $suffix,
        'o:terms' => ['Licencia ausente o incompatible', 'Otro motivo'],
    ])->getContent()->id();
    $created['vocabs'][] = $rejectVocab;
    $discardVocab = (int) $api->create('custom_vocabs', [
        'o:label' => TAG . ' motivos de descarte ' . $suffix,
        'o:terms' => ['Duplica un REA existente', 'Otro motivo'],
    ])->getContent()->id();
    $created['vocabs'][] = $discardVocab;
    $settings->set($reasonSettings[0], $rejectVocab);
    $settings->set($reasonSettings[1], $discardVocab);

    $r5 = $make($fix($copy('R5 motivos del vocabulario', false, $reaSiteId, false)));
    $p5Csrf = $p->csrf('/admin/item/' . $r5);
    $r = $p->post('propose', ['id' => $r5, 'csrf' => $p5Csrf]);
    check('proposer proposes R5', 'Propuesto' === ($r['status'] ?? null), json_encode($r));

    [, $drawer] = $c->request('GET', '/admin/oer-manager/drawer-details?id=' . $r5);
    check('the panel offers the reject reasons as a select',
        str_contains($drawer, 'id="oer-workflow-reason-reject-text" name="reason"')
        && str_contains($drawer, '<option value="Licencia ausente o incompatible">')
        && str_contains($drawer, 'name="detail"'));
    [, $page] = $c->request('GET', '/admin/item/' . $r5);
    check('the item page offers the reject reasons as a select',
        str_contains($page, 'id="oer-workflow-reject-' . $r5 . '" name="reason"')
        && str_contains($page, 'Licencia ausente o incompatible'));

    $r = $c->post('reject-proposal', ['id' => $r5, 'csrf' => $cCsrf, 'reason' => 'texto libre']);
    check('a reason outside the list is refused', 'reason_invalid' === ($r['error'] ?? null), json_encode($r));
    $r = $c->post('reject-proposal', ['id' => $r5, 'csrf' => $cCsrf,
        'reason' => 'Licencia ausente o incompatible', 'detail' => 'falta la licencia del vídeo 2']);
    check('curator rejects R5 with a term and a detail', 'Rechazado' === ($r['status'] ?? null), json_encode($r));
    $notes = $read($r5)->value('curation:note', ['all' => true, 'default' => []]);
    check('the note is the term (customvocab) plus the detail (literal), both private',
        2 === count($notes)
        && 'customvocab:' . $rejectVocab === $notes[0]->type() && 'Licencia ausente o incompatible' === (string) $notes[0]->value()
        && 'literal' === $notes[1]->type() && 'falta la licencia del vídeo 2' === (string) $notes[1]->value()
        && !$notes[0]->isPublic() && !$notes[1]->isPublic());
    [, $mine] = $p->request('GET', '/admin/oer-manager/my-proposals');
    check('«Mis propuestas» shows reason — detail',
        str_contains($mine, 'Licencia ausente o incompatible — falta la licencia del vídeo 2'));

    $r = $c->post('discard', ['id' => $r5, 'csrf' => $cCsrf, 'reason' => 'Licencia ausente o incompatible']);
    check('discard refuses a reject reason', 'reason_invalid' === ($r['error'] ?? null), json_encode($r));
    $r = $c->post('discard', ['id' => $r5, 'csrf' => $cCsrf, 'reason' => 'Duplica un REA existente']);
    check('curator discards R5 with a discard term', 'Descartado' === ($r['status'] ?? null), json_encode($r));
    $notes = $read($r5)->value('curation:note', ['all' => true, 'default' => []]);
    check('discarding replaced the whole note', 1 === count($notes)
        && 'customvocab:' . $discardVocab === $notes[0]->type());

    // A vocabulary that no longer exists falls back to free text.
    $settings->set($reasonSettings[0], 999999999);
    $r = $c->post('reopen', ['id' => $r5, 'csrf' => $cCsrf]);
    check('curator reopens R5', 'Propuesto' === ($r['status'] ?? null), json_encode($r));
    $notes = $read($r5)->value('curation:note', ['all' => true, 'default' => []]);
    check('reopening cleared the note', [] === $notes);
    [, $drawer] = $c->request('GET', '/admin/oer-manager/drawer-details?id=' . $r5);
    check('a missing vocabulary shows free text and the hint',
        str_contains($drawer, '<input type="text" id="oer-workflow-reason-reject-text" name="reason"')
        && str_contains($drawer, 'Lista de motivos no configurada'));
    $r = $c->post('reject-proposal', ['id' => $r5, 'csrf' => $cCsrf, 'reason' => 'texto libre ' . $suffix,
        'detail' => 'ignorado']);
    check('free text is accepted without a vocabulary', 'Rechazado' === ($r['status'] ?? null), json_encode($r));
    $notes = $read($r5)->value('curation:note', ['all' => true, 'default' => []]);
    check('free text is one literal; the detail is dropped',
        1 === count($notes) && 'literal' === $notes[0]->type());
```

- [ ] **Step 3: Clean up and check that nothing is left**

In the `finally` block, right after `$settings->set(ReaSite::SETTING, $previousReaSite);`:

```php
    foreach ($previousReasonVocabs ?? [] as $key => $value) {
        $settings->set($key, $value);
    }
    foreach ($created['vocabs'] as $vocabId) {
        try {
            $api->delete('custom_vocabs', $vocabId);
        } catch (\Throwable $e) {
            // Reported by the residue check below.
        }
    }
```

Next to the other residue checks, add:

```php
    $leftVocabs = (int) $services->get('Omeka\Connection')
        ->fetchOne('SELECT COUNT(*) FROM custom_vocab WHERE label LIKE ?', [TAG . '%']);
    check('no test vocabulary left', 0 === $leftVocabs);
    $restored = true;
    foreach ($previousReasonVocabs ?? [] as $key => $value) {
        $restored = $restored && $value === $settings->get($key);
    }
    check('reason vocabulary settings restored', $restored);
```

- [ ] **Step 4: Run it on the development container**

The harness writes only throwaway data and removes it. Run:
`docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/volume/modules/OERManager/test/container/rea-roles-check.php`

Expected: `… checks, 0 failures`, with every TASK-039 check OK and the before/after REA counts equal.
Pending lines that come from TASK-069 are expected; they are not failures. If a check fails because of
the harness itself, not the module (for example a selector that differs in the rendered HTML), fix the
check and say so in the commit message.

- [ ] **Step 5: Commit**

```bash
git add test/container/rea-roles-check.php
git commit -m "test: container check of the reason vocabularies with throwaway vocabularies (TASK-039)" \
  -m "Model: <the model that did this task>"
```

---

### Task 6: governance

**Files:**
- Modify: `docs/decisions/0018-flujo-autor-curador-rea.md` (append an addendum before «## Fuentes»)
- Modify: `docs/superpowers/specs/2026-11-23-task-039-reason-vocab-design.md` (one refinement)
- Modify: `docs/backlog.md` (TASK-039 and TASK-071 rows), `docs/traceability.md`, `docs/project-memory.md`

- [ ] **Step 1: ADR-0018 addendum**

Append before `## Fuentes`:

```markdown
## Addendum (2026-11-23) — the reason is a CustomVocab term; the status stays a literal (TASK-039)

Decided by the owner on 2026-11-23. `curation:note` now holds the reason to reject or discard as a term
of its own CustomVocab (`customvocab:N`, identified by the id in `oermanager_reject_reason_vocab_id` or
`oermanager_discard_reason_vocab_id`), followed by an optional free detail as a second, literal value.
The reason to unpublish stays free text. Without a usable vocabulary the reason falls back to free text,
so the flow is never blocked.

§2 still holds for `curation:status`. The state machine compares the status by exact equality, so an
editable vocabulary term could break the flow without warning. The note is never compared: it is
written, cleared and shown. That argument therefore does not apply to the note, and «seed, do not own»
(ADR-0013) does. Vocabulary terms are instance data and are not translated by the module (NFR-005).
```

- [ ] **Step 2: Spec refinement (append-only)**

Append to the end of «### 2. Configuration and validation» in the spec:

```markdown
- **Refinement during planning (2026-11-23).** One service, `Service\Workflow\ReasonVocabularies`, wraps
  the two `VocabEntries` instead of registering two services. It keeps the controller to one new
  dependency. Its reader is `Service\Governance\CustomVocabTerms`, which yields the terms of a term-typed
  vocabulary only, so a URI or item-set vocabulary counts as not configured. The item page's reason form
  already opens in a panel under the 48px bar, so the select and the detail field do not have to fit in
  the bar.
```

- [ ] **Step 3: Backlog, traceability and project memory**

- **`docs/backlog.md`, TASK-039:**
  - set the status cell to `hecha`;
  - append to its description: «**Closed on 2026-11-23** ([spec](superpowers/specs/2026-11-23-task-039-reason-vocab-design.md),
    [plan](superpowers/plans/2026-11-23-task-039-reason-vocab.md)). Reasons to reject and to discard come
    from two term CustomVocab, set by id in the configuration and created by hand; an optional detail of
    200 characters; free text when no vocabulary resolves; unpublish stays free text. ADR-0018 addendum.
    Verified with `make test`, `make lint` and `test/container/rea-roles-check.php` with throwaway
    vocabularies.»
  - In its last column, replace the pending owner decision with `—`.
- **`docs/backlog.md`, TASK-071:** append «**TASK-039 (2026-11-23):** create the two CustomVocab of
  reasons with the lists in the [TASK-039 spec](superpowers/specs/2026-11-23-task-039-reason-vocab-design.md)
  and set their ids in the module configuration.»
- **`docs/traceability.md`:** in the row that names `TASK-039`, mark it **hecha (2026-11-23)**. Add a
  bullet after the ADR-0024 bullet: «**ADR-0018 addendum (2026-11-23, TASK-039).** Reasons to reject and
  to discard as CustomVocab terms plus an optional detail; the status stays a literal. NFR-005: vocabulary
  terms are instance data, not translated by the module.»
- **`docs/project-memory.md`:** add an entry after the last one: «**TASK-039 closed (2026-11-23) — a
  controlled reason that never blocks the flow.**» Add the lessons the implementation actually found;
  write the real ones, not a template.

- [ ] **Step 4: Final verification**

Run: `make lint && make test && make test-js`
Expected: all pass. Record the PHPUnit test count in the project-memory entry.

- [ ] **Step 5: Commit**

```bash
git add docs
git commit -m "docs: close TASK-039 (ADR-0018 addendum, backlog, traceability, project memory)" \
  -m "Model: <the model that did this task>"
```
