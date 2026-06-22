<?php

namespace OERManager\Form;

use Laminas\Form\Form;
use OERManager\Service\CurriculumSearch;

/**
 * Formulario de configuración del módulo (module.ini: configurable = true).
 *
 * Localización de los DefinedTermSet raíz (ADR-0006): el propietario fija el
 * item-raíz de ejes temáticos y el valor de lrmi:educationalFramework de los
 * marcos curriculares. La conexión LLM (RF-010/ADR-0008) se añade en la
 * entrega 4b (TASK-010).
 */
class ConfigForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name' => CurriculumSearch::AXIS_SETTING,
            'type' => 'Number',
            'options' => [
                'label' => 'Item-raíz de ejes temáticos (DefinedTermSet de tags)', // @translate
                'info' => 'ID del item schema:DefinedTermSet de los ejes (dcterms:relation).', // @translate
            ],
            'attributes' => [
                'id' => CurriculumSearch::AXIS_SETTING,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->add([
            'name' => CurriculumSearch::FRAMEWORK_SETTING,
            'type' => 'Text',
            'options' => [
                'label' => 'Marco curricular (lrmi:educationalFramework)', // @translate
                'info' => 'Valor que delimita los DefinedTermSet curriculares (p. ej. LOMLOE).', // @translate
            ],
            'attributes' => [
                'id' => CurriculumSearch::FRAMEWORK_SETTING,
            ],
        ]);
    }
}
