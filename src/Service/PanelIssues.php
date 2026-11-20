<?php

declare(strict_types=1);

namespace OERManager\Service;

use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\Governance\IntegrityPolicy;
use OERManager\Service\Workflow\WorkflowStatus;

/**
 * How the detail panel shows integrity issues (TASK-065, spec A6).
 *
 * The rules and their messages stay in `IntegrityPolicy`; this only decides
 * WHERE each issue is read and HOW it is worded for the curator:
 *
 * - In the tab that fixes it (Currículo, Gobernanza, Recurso). Issues about the
 *   workflow itself go to the flow bar instead.
 * - In plain language: a message that quotes the RDF term (`"lrmi:teaches"`)
 *   names the field by its label instead, and the missing anchors — four lines
 *   with the same cause in a REA without alignment — become one sentence.
 *
 * Counts stay per ISSUE, not per line, so the panel says the same number as
 * the integrity column of the table.
 */
final class PanelIssues
{
    public const TAB_CURRICULUM = 'curriculum';
    public const TAB_GOVERNANCE = 'governance';
    public const TAB_RESOURCE = 'resource';
    public const FLOW = 'flow';

    /** Tabs that can hold issues, in the order the panel shows them. */
    private const TAB_ORDER = [self::TAB_CURRICULUM, self::TAB_GOVERNANCE, self::TAB_RESOURCE];

    private const SEVERITY_ORDER = ['error' => 0, 'warning' => 1];

    public static function tabOf(string $field): string
    {
        if (in_array($field, IntegrityPolicy::ALIGNMENT_TERMS, true) || 'dcterms:relation' === $field) {
            return self::TAB_CURRICULUM;
        }
        if (IntegrityPolicy::LICENSE_TERM === $field || GovernanceFields::isGovernanceTerm($field)) {
            return self::TAB_GOVERNANCE;
        }
        if (WorkflowStatus::STATUS_TERM === $field || 'o:site' === $field) {
            return self::FLOW;
        }
        return self::TAB_RESOURCE;
    }

    /**
     * @param list<array{severity:string, code:string, field:string, message:string}> $issues
     * @param array<string,string> $labels field → its label in the panel
     * @param (callable(string):string)|null $translate for the sentences composed here
     * @return array{
     *     tabs: array<string, list<array{severity:string, text:string}>>,
     *     flow: list<array{severity:string, text:string}>,
     *     counts: array<string, array<string,int>>,
     *     first: string|null
     * }
     */
    public static function build(array $issues, array $labels, ?callable $translate = null): array
    {
        $t = $translate ?? static fn (string $text): string => $text;

        $lines = [];
        $counts = [];
        $missing = [];
        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? 'warning');
            $field = (string) ($issue['field'] ?? '');
            $tab = self::tabOf($field);
            if (self::FLOW !== $tab) {
                $counts[$tab][$severity] = ($counts[$tab][$severity] ?? 0) + 1;
            }
            if ('missing_alignment' === ($issue['code'] ?? '')) {
                $missing[$tab][$severity][] = $labels[$field] ?? $field;
                continue;
            }
            $lines[$tab][] = ['severity' => $severity, 'text' => self::plain($issue, $labels)];
        }

        foreach ($missing as $tab => $bySeverity) {
            foreach ($bySeverity as $severity => $names) {
                $lines[$tab][] = ['severity' => $severity, 'text' => sprintf(
                    $t('Falta el anclaje curricular: %s.'), // @translate
                    self::listOf(array_map('mb_strtolower', $names), $t)
                )];
            }
        }

        $tabs = [];
        foreach (self::TAB_ORDER as $tab) {
            if (isset($lines[$tab])) {
                $tabs[$tab] = self::sorted($lines[$tab]);
            }
        }
        $first = [] === $tabs ? null : array_key_first($tabs);

        return [
            'tabs' => $tabs,
            'flow' => self::sorted($lines[self::FLOW] ?? []),
            'counts' => array_intersect_key($counts, $tabs),
            'first' => $first,
        ];
    }

    /**
     * How many issues the publish gate checks. The panel says «impiden
     * publicar» only when there are some.
     *
     * @param list<array{code:string}> $issues
     */
    public static function blockingPublish(array $issues): int
    {
        return count(array_filter(
            $issues,
            static fn (array $issue): bool => !in_array(
                $issue['code'] ?? '',
                IntegrityPolicy::PUBLISH_GATE_IGNORES,
                true
            )
        ));
    }

    /** The policy's message, with the quoted RDF term replaced by its label. */
    private static function plain(array $issue, array $labels): string
    {
        $field = (string) ($issue['field'] ?? '');
        $message = (string) ($issue['message'] ?? '');
        if ('' === $field || !isset($labels[$field])) {
            return $message;
        }
        return str_replace('"' . $field . '"', '«' . $labels[$field] . '»', $message);
    }

    /** «a», «a y b», «a, b y c». */
    private static function listOf(array $names, callable $t): string
    {
        if (count($names) < 2) {
            return (string) ($names[0] ?? '');
        }
        $last = array_pop($names);
        return sprintf($t('%1$s y %2$s'), implode(', ', $names), $last); // @translate
    }

    /** Errors first; the order inside a severity is kept. */
    private static function sorted(array $lines): array
    {
        usort(
            $lines,
            static fn (array $a, array $b): int =>
                (self::SEVERITY_ORDER[$a['severity']] ?? 9) <=> (self::SEVERITY_ORDER[$b['severity']] ?? 9)
        );
        return $lines;
    }
}
