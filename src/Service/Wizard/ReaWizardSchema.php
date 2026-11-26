<?php

declare(strict_types=1);

namespace OERManager\Service\Wizard;

use OERManager\Service\Governance\GovernanceFields;

/**
 * What the wizard shows, from the REA template's properties (TASK-077 spec,
 * «② Datos»). Pure: the controller reads the template and hands its
 * properties here as plain arrays.
 *
 * Three kinds of property:
 * - **data**: shown in step ②, in the template's order, with its label;
 * - **anchoring**: the five curricular and axis terms, filled in step ③ and
 *   written only by RecatalogService (ADR-0015);
 * - **machine**: never shown (workflow, stored proposal, audit ledger).
 */
final class ReaWizardSchema
{
    /** Written by RecatalogService::apply, in step ③ (ADR-0004 as amended by ADR-0022). */
    public const ANCHORING_TERMS = [
        'lrmi:educationalLevel',
        'schema:about',
        'lrmi:teaches',
        'lrmi:assesses',
        'dcterms:relation',
    ];

    /** Never shown: WorkflowService, the stored AI proposal and the audit ledger own them. */
    public const MACHINE_TERMS = ['curation:status', 'curation:note', 'curation:data', 'dcterms:provenance'];

    /**
     * The main resource when it is a link (code review of PR #100): Omeka's `url`
     * ingester downloads its target and refuses a web page, so a link is a URI
     * value of the REA, managed by step ①, never a data field.
     */
    public const LINK_TERM = 'schema:url';

    public const RESOURCE_TYPE_TERM = 'lrmi:learningResourceType';
    public const LICENCE_TERM = 'dcterms:license';
    public const DESCRIPTION_TERM = 'dcterms:description';
    public const TITLE_TERM = 'dcterms:title';

    /** One line of help in the teacher's language for the terms the module knows. */
    private const HELP = [
        'dcterms:title' => 'El nombre con el que otros docentes encontrarán tu recurso.', // @translate
        'dcterms:description' => 'En dos o tres frases: qué hace el alumnado y qué aprende.', // @translate
        'lrmi:learningResourceType' => 'Elige el que mejor lo describe.', // @translate
        'dcterms:license' => 'Qué pueden hacer otras personas con tu recurso.', // @translate
        'dcterms:creator' => 'Quién lo ha hecho: personas, centro o equipo.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'schema:isPartOf' => 'Si el recurso nació en un proyecto o programa educativo, indícalo.', // @translate
    ];

    /**
     * Each property: term, label, alternateLabel (nullable), required, dataTypes (list).
     * Each data field: term, label, required, control, dataType, vocabId (nullable), help.
     *
     * @param list<array<string,mixed>> $properties
     * @return array{data:list<array<string,mixed>>,anchoring:list<string>}
     */
    public static function fromTemplate(array $properties): array
    {
        $data = [];
        $seen = [];
        foreach ($properties as $property) {
            $term = (string) $property['term'];
            $dataType = (string) ($property['dataTypes'][0] ?? 'literal');
            // A resource value (the project, `schema:isPartOf`) cannot be edited as text: the
            // wizard neither shows nor clears it (code review of PR #100).
            $hidden = in_array($term, self::ANCHORING_TERMS, true) || in_array($term, self::MACHINE_TERMS, true)
                || self::LINK_TERM === $term || str_starts_with($dataType, 'resource');
            if ($hidden || isset($seen[$term])) {
                continue;
            }
            $seen[$term] = true;
            $alternate = trim((string) ($property['alternateLabel'] ?? ''));
            $data[] = [
                'term' => $term,
                'label' => '' !== $alternate ? $alternate : (string) $property['label'],
                'required' => (bool) $property['required'],
                'control' => self::control($term, $dataType),
                'dataType' => $dataType,
                'vocabId' => self::vocabId($dataType),
                'help' => self::HELP[$term] ?? '',
            ];
        }
        // The title (a draft needs it) and the licence (proposing needs it) are always offered,
        // even with a template that lacks them: otherwise «Le falta» could never be cleared.
        if (!isset($seen[self::TITLE_TERM])) {
            array_unshift($data, self::synthetic(self::TITLE_TERM, 'Título', 'text')); // @translate
        }
        if (!isset($seen[self::LICENCE_TERM])) {
            $data[] = self::synthetic(self::LICENCE_TERM, 'Licencia', 'licence'); // @translate
        }
        return ['data' => $data, 'anchoring' => self::ANCHORING_TERMS];
    }

    /** @return array<string,mixed> */
    private static function synthetic(string $term, string $label, string $control): array
    {
        return [
            'term' => $term,
            'label' => $label,
            'required' => false,
            'control' => $control,
            'dataType' => 'licence' === $control ? 'uri' : 'literal',
            'vocabId' => null,
            'help' => self::HELP[$term] ?? '',
        ];
    }

    /**
     * Required data terms of a schema, for MinimumRule.
     *
     * @param array{data:list<array{term:string,required:bool}>} $schema
     * @return list<string>
     */
    public static function requiredDataTerms(array $schema): array
    {
        $terms = [];
        foreach ($schema['data'] as $field) {
            if ($field['required']) {
                $terms[] = $field['term'];
            }
        }
        return $terms;
    }

    /**
     * The options of a CustomVocab, from its type and `listValues()` (terms by
     * term, URIs by URI, items by id). An item-set vocabulary (such as the
     * projects of `schema:isPartOf`) offers its items: the option's value is
     * the item id as text, and `resource` tells the save to link the item.
     *
     * @param array<int|string,string> $values
     * @return list<array<string,mixed>>
     */
    public static function vocabOptions(?string $type, array $values): array
    {
        $options = [];
        foreach ($values as $key => $label) {
            if ('resource' === $type) {
                $options[] = ['value' => (string) $key, 'label' => (string) $label, 'resource' => (int) $key];
            } elseif ('uri' === $type) {
                $options[] = ['uri' => (string) $key, 'label' => (string) $label];
            } elseif ('literal' === $type) {
                $options[] = ['value' => (string) $key];
            }
        }
        return $options;
    }

    /** Written by GovernanceService (RF-015), not in the item payload. */
    public static function isGovernance(string $term): bool
    {
        return GovernanceFields::isGovernanceTerm($term);
    }

    private static function control(string $term, string $dataType): string
    {
        if (self::LICENCE_TERM === $term) {
            return 'licence';
        }
        if (self::DESCRIPTION_TERM === $term) {
            return 'textarea';
        }
        if (null !== self::vocabId($dataType)) {
            return self::RESOURCE_TYPE_TERM === $term ? 'cards' : 'select';
        }
        return 'uri' === $dataType ? 'url' : 'text';
    }

    private static function vocabId(string $dataType): ?int
    {
        return 1 === preg_match('/^customvocab:([1-9]\d*)$/', $dataType, $m) ? (int) $m[1] : null;
    }
}
