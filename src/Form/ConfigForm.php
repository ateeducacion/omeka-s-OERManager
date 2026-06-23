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

        // Valor de dcterms:type que distingue cada dimensión curricular dentro
        // del marco (ADR-0006 + docs/referencia/curriculo-modelo-rdf.md). El
        // re-catalogador acota cada input a los términos de su dcterms:type.
        $typeLabels = [
            'lrmi:educationalLevel' => 'dcterms:type de Etapa (lrmi:educationalLevel)', // @translate
            'schema:about' => 'dcterms:type de Materia (schema:about)', // @translate
            'lrmi:teaches' => 'dcterms:type de Saberes (lrmi:teaches)', // @translate
            'lrmi:assesses' => 'dcterms:type de Criterios (lrmi:assesses)', // @translate
        ];
        foreach (CurriculumSearch::TYPE_SETTINGS as $dimension => $setting) {
            $this->add([
                'name' => $setting,
                'type' => 'Text',
                'options' => [
                    'label' => $typeLabels[$dimension],
                    'info' => 'Valor de dcterms:type de los términos de esta dimensión.', // @translate
                ],
                'attributes' => [
                    'id' => $setting,
                ],
            ]);
        }
    }
}
