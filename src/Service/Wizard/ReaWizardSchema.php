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

    public const RESOURCE_TYPE_TERM = 'lrmi:learningResourceType';
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
        'schema:isPartOf' => 'Si el recurso nació en un proyecto o programa educativo, escribe su nombre.', // @translate
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
            $hidden = in_array($term, self::ANCHORING_TERMS, true) || in_array($term, self::MACHINE_TERMS, true);
            if ($hidden || isset($seen[$term])) {
                continue;
            }
            $seen[$term] = true;
            $dataType = (string) ($property['dataTypes'][0] ?? 'literal');
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
        return ['data' => $data, 'anchoring' => self::ANCHORING_TERMS];
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

    /** Written by GovernanceService (RF-015), not in the item payload. */
    public static function isGovernance(string $term): bool
    {
        return GovernanceFields::isGovernanceTerm($term);
    }

    private static function control(string $term, string $dataType): string
    {
        if (GovernanceFields::LICENCE === $term) {
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
