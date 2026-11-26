<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\ReaWizardSchema;
use PHPUnit\Framework\TestCase;

final class ReaWizardSchemaTest extends TestCase
{
    private static function prop(string $term, array $dataTypes = ['literal'], bool $required = false, ?string $alt = null): array
    {
        return ['term' => $term, 'label' => 'L ' . $term, 'alternateLabel' => $alt, 'required' => $required, 'dataTypes' => $dataTypes];
    }

    public function testDataFieldsKeepTheTemplateOrderAndItsLabels(): void
    {
        $schema = ReaWizardSchema::fromTemplate([
            self::prop('dcterms:title', ['literal'], true, 'Título del recurso'),
            self::prop('dcterms:description'),
        ]);
        $this->assertSame(['dcterms:title', 'dcterms:description', 'dcterms:license'], array_column($schema['data'], 'term'));
        $this->assertSame('Título del recurso', $schema['data'][0]['label']);
        $this->assertSame('L dcterms:description', $schema['data'][1]['label']);
        $this->assertTrue($schema['data'][0]['required']);
        $this->assertFalse($schema['data'][1]['required']);
    }

    public function testControlsByTermAndDataType(): void
    {
        $schema = ReaWizardSchema::fromTemplate([
            self::prop('dcterms:title'),
            self::prop('dcterms:description'),
            self::prop('lrmi:learningResourceType', ['customvocab:7']),
            self::prop('dcterms:language', ['customvocab:9']),
            self::prop('dcterms:license', ['customvocab:3']),
            self::prop('dcterms:references', ['uri']),
            self::prop('dcterms:creator', ['literal']),
        ]);
        $controls = array_column($schema['data'], 'control', 'term');
        $this->assertSame([
            'dcterms:title' => 'text',
            'dcterms:description' => 'textarea',
            'lrmi:learningResourceType' => 'cards',
            'dcterms:language' => 'select',
            'dcterms:license' => 'licence',
            'dcterms:references' => 'url',
            'dcterms:creator' => 'text',
        ], $controls);
        $vocabs = array_column($schema['data'], 'vocabId', 'term');
        $this->assertSame(7, $vocabs['lrmi:learningResourceType']);
        $this->assertSame(9, $vocabs['dcterms:language']);
        $this->assertNull($vocabs['dcterms:title']);
    }

    public function testAnchoringAndMachineTermsAreNeverDataFields(): void
    {
        $terms = array_merge(ReaWizardSchema::ANCHORING_TERMS, ReaWizardSchema::MACHINE_TERMS);
        $schema = ReaWizardSchema::fromTemplate(array_map(fn (string $t) => self::prop($t), [...$terms, 'dcterms:title']));
        $this->assertSame(['dcterms:title', 'dcterms:license'], array_column($schema['data'], 'term'));
        $this->assertSame(ReaWizardSchema::ANCHORING_TERMS, $schema['anchoring']);
    }

    public function testTheTitleAndTheLicenceAreAlwaysThereEvenIfTheTemplateLacksThem(): void
    {
        $schema = ReaWizardSchema::fromTemplate([self::prop('dcterms:description')]);
        $this->assertSame(['dcterms:title', 'dcterms:description', 'dcterms:license'], array_column($schema['data'], 'term'));
        $this->assertSame(['text', 'textarea', 'licence'], array_column($schema['data'], 'control'));
        $this->assertSame(ReaWizardSchema::ANCHORING_TERMS, $schema['anchoring']);
    }

    /** Code review of PR #100: a resource value (the project) cannot be edited as text, so it is not touched. */
    public function testResourceValuedAndLinkTermsAreNeverDataFields(): void
    {
        $schema = ReaWizardSchema::fromTemplate([
            self::prop('dcterms:title'),
            self::prop('schema:isPartOf', ['resource:item']),
            self::prop('dcterms:hasPart', ['resource']),
            self::prop('schema:url', ['uri']),
        ]);
        $this->assertSame(['dcterms:title', 'dcterms:license'], array_column($schema['data'], 'term'));
    }

    public function testHelpOnlyForTheKnownTerms(): void
    {
        $schema = ReaWizardSchema::fromTemplate([self::prop('dcterms:title'), self::prop('dcterms:language')]);
        $this->assertNotSame('', $schema['data'][0]['help']);
        $this->assertSame('', $schema['data'][1]['help']);
    }

    public function testATermListedTwiceIsShownOnce(): void
    {
        $schema = ReaWizardSchema::fromTemplate([self::prop('dcterms:title'), self::prop('dcterms:title')]);
        $this->assertSame(['dcterms:title', 'dcterms:license'], array_column($schema['data'], 'term'));
    }

    public function testRequiredTermsAndGovernance(): void
    {
        $schema = ReaWizardSchema::fromTemplate([
            self::prop('dcterms:title', ['literal'], true),
            self::prop('dcterms:license', ['customvocab:3'], true),
            self::prop('lrmi:teaches', ['resource:item'], true),
        ]);
        $this->assertSame(['dcterms:title', 'dcterms:license'], ReaWizardSchema::requiredDataTerms($schema));
        $this->assertTrue(ReaWizardSchema::isGovernance('dcterms:license'));
        $this->assertFalse(ReaWizardSchema::isGovernance('dcterms:title'));
    }
}
