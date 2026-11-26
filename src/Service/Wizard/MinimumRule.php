<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

use OERManager\Service\Governance\GovernanceFields;

/**
 * What «Guardar y proponer» requires (TASK-077 spec, owner decision of
 * 2026-11-26): the template's required fields, the licence, a minimum
 * anchoring (a course and a course subject; the stage is navigation and is
 * not written) and the attached resource. Mirrored client-side by
 * `asset/js/core/wizardModel.js`, which shows «Le falta» from these codes.
 *
 * The state is the wizard's: `resource` (bool), `values` (term => strings)
 * and `alignment` (term => ids).
 */
final class MinimumRule
{
    public const RESOURCE = 'resource';
    public const LICENCE = 'licence';
    public const COURSE = 'course';
    public const SUBJECT = 'subject';
    public const FIELD_PREFIX = 'field:';

    private const COURSE_TERM = 'lrmi:educationalLevel';
    private const SUBJECT_TERM = 'schema:about';

    /**
     * @param array{resource?:bool,values?:array<string,list<string>>,alignment?:array<string,list<int>>} $state
     * @param list<string> $requiredTerms the template's required properties, in its order
     * @return list<string> missing codes, in the order «Le falta» lists them
     */
    public static function missing(array $state, array $requiredTerms): array
    {
        $missing = [];
        if (!($state['resource'] ?? false)) {
            $missing[] = self::RESOURCE;
        }
        $special = [GovernanceFields::LICENCE, self::COURSE_TERM, self::SUBJECT_TERM];
        $anchoring = [];
        foreach ($requiredTerms as $term) {
            if (in_array($term, $special, true) || self::has($state, $term)) {
                continue;
            }
            // Step order: data fields (②) before the anchoring (③).
            if (in_array($term, ReaWizardSchema::ANCHORING_TERMS, true)) {
                $anchoring[] = self::FIELD_PREFIX . $term;
            } else {
                $missing[] = self::FIELD_PREFIX . $term;
            }
        }
        if (!self::has($state, GovernanceFields::LICENCE)) {
            $missing[] = self::LICENCE;
        }
        if (!self::has($state, self::COURSE_TERM)) {
            $missing[] = self::COURSE;
        }
        if (!self::has($state, self::SUBJECT_TERM)) {
            $missing[] = self::SUBJECT;
        }
        return array_merge($missing, $anchoring);
    }

    /**
     * Whether every required template property has a value: Omeka refuses an
     * item with the template otherwise, so the template is set only then.
     *
     * @param list<string> $requiredTerms
     */
    public static function meetsTemplate(array $state, array $requiredTerms): bool
    {
        foreach ($requiredTerms as $term) {
            if (!self::has($state, $term)) {
                return false;
            }
        }
        return true;
    }

    private static function has(array $state, string $term): bool
    {
        if (in_array($term, ReaWizardSchema::ANCHORING_TERMS, true)) {
            return [] !== array_filter((array) ($state['alignment'][$term] ?? []), static fn ($id) => (int) $id > 0);
        }
        return [] !== array_filter(
            (array) ($state['values'][$term] ?? []),
            static fn ($value) => '' !== trim((string) $value)
        );
    }
}
