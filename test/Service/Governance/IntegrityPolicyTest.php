<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\IntegrityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Reglas de integridad (RF-006) sobre datos planos, para que sean probables en
 * el host: IntegrityChecker tipa ItemRepresentation y no se puede instanciar
 * fuera del contenedor (limitación del arnés, TASK-008).
 */
final class IntegrityPolicyTest extends TestCase
{
    /** Valor de enlace sano: apunta a un item-término existente. */
    private function link(): array
    {
        return ['type' => 'resource:item', 'hasResource' => true];
    }

    /** Un REA completo: las cuatro properties de anclaje enlazadas + licencia. */
    private function healthy(): array
    {
        $values = [IntegrityPolicy::LICENSE_TERM => [['type' => 'literal', 'hasResource' => false]]];
        foreach (IntegrityPolicy::ALIGNMENT_TERMS as $term) {
            $values[$term] = [$this->link()];
        }
        return $values;
    }

    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    public function testHealthyItemHasNoIssues(): void
    {
        $this->assertSame([], IntegrityPolicy::issuesFor($this->healthy(), [], false));
    }

    public function testMissingLicenceIsAWarning(): void
    {
        $values = $this->healthy();
        unset($values[IntegrityPolicy::LICENSE_TERM]);

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['missing_license'], $this->codes($issues));
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertSame(IntegrityPolicy::LICENSE_TERM, $issues[0]['field']);
    }

    public function testEachMissingAlignmentTermIsItsOwnWarning(): void
    {
        $values = $this->healthy();
        unset($values['lrmi:teaches'], $values['lrmi:assesses']);

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['missing_alignment', 'missing_alignment'], $this->codes($issues));
        $this->assertSame(['lrmi:teaches', 'lrmi:assesses'], array_column($issues, 'field'));
    }

    /**
     * D2 del estudio (TASK-027): hoy un literal en una property de enlace cuenta
     * como valor presente y la integridad dice «ok». Son 4 valores del catálogo
     * real (schema:about ×3, lrmi:educationalLevel ×1).
     */
    public function testLiteralInALinkPropertyIsAWarning(): void
    {
        $values = $this->healthy();
        $values['schema:about'] = [['type' => 'literal', 'hasResource' => false]];

        $issues = IntegrityPolicy::issuesFor($values, [], false);

        $this->assertSame(['literal_in_link_property'], $this->codes($issues));
        $this->assertSame('warning', $issues[0]['severity']);
        $this->assertSame('schema:about', $issues[0]['field']);
    }

    public function testALiteralIsNotAlsoReportedAsMissing(): void
    {
        $values = $this->healthy();
        $values['schema:about'] = [['type' => 'literal', 'hasResource' => false]];

        $this->assertNotContains('missing_alignment', $this->codes(
            IntegrityPolicy::issuesFor($values, [], false)
        ));
    }

    /**
     * D-6: la plantilla SUMA sus campos obligatorios, ya no sustituye al mínimo.
     * Antes de este cambio, asignar una plantilla silenciaba anclaje y licencia
     * (la trampa que TASK-027 §8.1 anticipó y que PEND-012 iba a abrir).
     */
    public function testTemplateRequirementsAddToTheMinimumInsteadOfReplacingIt(): void
    {
        $values = $this->healthy();
        unset($values[IntegrityPolicy::LICENSE_TERM]);

        $issues = IntegrityPolicy::issuesFor($values, ['dcterms:description'], false);

        $this->assertSame(['missing_license', 'missing_required'], $this->codes($issues));
        $this->assertSame('dcterms:description', $issues[1]['field']);
    }

    public function testSatisfiedTemplateRequirementRaisesNothing(): void
    {
        $values = $this->healthy();
        $values['dcterms:description'] = [['type' => 'literal', 'hasResource' => false]];

        $this->assertSame([], IntegrityPolicy::issuesFor($values, ['dcterms:description'], false));
    }

    /** D-7: la comprobación de enlace vivo está apagada en columna y filtro. */
    public function testDeadLinkIsOnlyReportedWhenLinkCheckingIsOn(): void
    {
        $values = $this->healthy();
        $values['lrmi:teaches'] = [['type' => 'resource:item', 'hasResource' => false]];

        $this->assertSame([], IntegrityPolicy::issuesFor($values, [], false));

        $issues = IntegrityPolicy::issuesFor($values, [], true);
        $this->assertSame(['dead_link'], $this->codes($issues));
        $this->assertSame('error', $issues[0]['severity']);
    }

    public function testEmptyValueListCountsAsMissing(): void
    {
        $values = $this->healthy();
        $values['schema:about'] = [];

        $this->assertSame(['missing_alignment'], $this->codes(
            IntegrityPolicy::issuesFor($values, [], false)
        ));
    }
}
