<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\GovernanceColumns;
use OERManager\Service\Governance\IntegrityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Columnas de gobernanza con property fija (TASK-042). Antes solo existían vía
 * `column_defaults`: el tipo genérico no tiene formulario de datos, así que desde
 * el selector de columnas del usuario no se podía decir qué property mostrar.
 */
final class GovernanceColumnsTest extends TestCase
{
    public function testLicenceColumnReadsTheLicenceTerm(): void
    {
        $data = GovernanceColumns::resolve(GovernanceColumns::LICENCE, []);

        $this->assertSame(IntegrityPolicy::LICENSE_TERM, $data['property_term']);
        $this->assertSame('Sin licencia', $data['empty_label']);
    }

    public function testResourceTypeColumnReadsTheResourceTypeTerm(): void
    {
        $data = GovernanceColumns::resolve(GovernanceColumns::RESOURCE_TYPE, []);

        $this->assertSame('lrmi:learningResourceType', $data['property_term']);
        $this->assertSame('Sin tipo', $data['empty_label']);
    }

    /** Una selección guardada con la property antigua no puede ganar a la fija. */
    public function testFixedTermWinsOverSavedData(): void
    {
        $data = GovernanceColumns::resolve(GovernanceColumns::LICENCE, ['property_term' => 'dcterms:rights']);

        $this->assertSame(IntegrityPolicy::LICENSE_TERM, $data['property_term']);
    }

    /** La cabecera que el usuario escriba en su selector se respeta. */
    public function testUserHeaderAndDefaultArePreserved(): void
    {
        $data = GovernanceColumns::resolve(GovernanceColumns::LICENCE, ['header' => 'Lic.', 'default' => '—']);

        $this->assertSame('Lic.', $data['header']);
        $this->assertSame('—', $data['default']);
    }

    /** El tipo genérico (sin clave) sigue leyendo lo que traiga la configuración. */
    public function testGenericColumnKeepsItsConfiguredData(): void
    {
        $saved = ['property_term' => 'dcterms:rights', 'header' => 'Licencia', 'empty_label' => 'Sin licencia'];

        $this->assertSame($saved, GovernanceColumns::resolve(null, $saved));
    }

    public function testEachFixedColumnHasALabel(): void
    {
        $this->assertSame('Licencia', GovernanceColumns::label(GovernanceColumns::LICENCE));
        $this->assertSame('Tipo de recurso', GovernanceColumns::label(GovernanceColumns::RESOURCE_TYPE));
    }
}
