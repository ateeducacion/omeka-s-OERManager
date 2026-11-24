<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\GovernanceSettings;
use OERManager\Service\ReaTemplateFields;
use Omeka\Api\Manager;
use Omeka\Api\Representation\PropertyRepresentation;
use Omeka\Api\Representation\ResourceTemplatePropertyRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Api\Response;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/** TASK-070: «Sin campo obligatorio» reads the REA template's required properties. */
final class ReaTemplateFieldsTest extends TestCase
{
    private function templateProperty(string $term, bool $required): ResourceTemplatePropertyRepresentation
    {
        $property = $this->createMock(PropertyRepresentation::class);
        $property->method('term')->willReturn($term);
        $templateProperty = $this->createMock(ResourceTemplatePropertyRepresentation::class);
        $templateProperty->method('isRequired')->willReturn($required);
        $templateProperty->method('property')->willReturn($property);
        return $templateProperty;
    }

    public function testListsOnlyRequiredTermsAndReadsOnce(): void
    {
        $template = $this->createMock(ResourceTemplateRepresentation::class);
        $template->method('resourceTemplateProperties')->willReturn([
            $this->templateProperty('dcterms:title', true),
            $this->templateProperty('dcterms:subject', false),
            $this->templateProperty('dcterms:license', true),
        ]);
        $api = $this->createMock(Manager::class);
        $api->expects($this->once())->method('read')
            ->with('resource_templates', 3)
            ->willReturn(new Response($template));
        $settings = new Settings();
        $settings->set(GovernanceSettings::REA_TEMPLATE_ID, '3');

        $fields = new ReaTemplateFields($api, $settings);

        $this->assertSame(['dcterms:title', 'dcterms:license'], $fields->requiredTerms());
        $this->assertSame(['dcterms:title', 'dcterms:license'], $fields->requiredTerms());
    }

    public function testNoTemplateConfiguredMeansNoTerms(): void
    {
        $api = $this->createMock(Manager::class);
        $api->expects($this->never())->method('read');

        $this->assertSame([], (new ReaTemplateFields($api, new Settings()))->requiredTerms());
    }

    public function testAnUnreadableTemplateMeansNoTerms(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('read')->willThrowException(new \RuntimeException('gone'));
        $settings = new Settings();
        $settings->set(GovernanceSettings::REA_TEMPLATE_ID, '99');

        $this->assertSame([], (new ReaTemplateFields($api, $settings))->requiredTerms());
    }
}
