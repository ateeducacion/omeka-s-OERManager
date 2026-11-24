<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;

/**
 * Required properties of the configured REA template (TASK-070), for the
 * master view's «Sin campo obligatorio» filter. Read once per request.
 * `[]` when no template is configured or it cannot be read: the filter is
 * then not offered and its parameter changes nothing.
 *
 * Not final so the controller tests can stand it in.
 */
class ReaTemplateFields
{
    /** @var list<string>|null */
    private ?array $required = null;

    public function __construct(
        private readonly ApiManager $api,
        private readonly Settings $settings
    ) {
    }

    /** @return list<string> */
    public function requiredTerms(): array
    {
        if (null !== $this->required) {
            return $this->required;
        }
        $terms = [];
        $id = GovernanceSettings::parseId($this->settings->get(GovernanceSettings::REA_TEMPLATE_ID));
        if (null !== $id) {
            try {
                $template = $this->api->read('resource_templates', $id)->getContent();
                foreach ($template->resourceTemplateProperties() as $templateProperty) {
                    if ($templateProperty->isRequired()) {
                        $terms[] = (string) $templateProperty->property()->term();
                    }
                }
            } catch (\Throwable $e) {
                $terms = [];
            }
        }
        return $this->required = array_values(array_unique($terms));
    }
}
