<?php

namespace OERManager\Form;

use Laminas\Form\Form;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\Llm\LlmSettings;

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
        // El lrmi:educationalLevel del REA referencia un Curso, no la Etapa
        // (ADR-0009). Valores reales de referencia: Curso, Asignatura,
        // Saber básico, Criterio de evaluación.
        $typeLabels = [
            'lrmi:educationalLevel' => 'dcterms:type de Curso (lrmi:educationalLevel)', // @translate
            'schema:about' => 'dcterms:type de Asignatura (schema:about)', // @translate
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

        $this->addLlmFields();
    }

    /**
     * Conexión LLM para la catalogación IA-assistida (TASK-010, ADR-0008). La
     * clave API es write-only: no se devuelve en claro al formulario (Module la
     * deja en blanco) y solo se actualiza si se introduce un valor nuevo.
     */
    private function addLlmFields(): void
    {
        $this->add([
            'name' => LlmSettings::ENABLED,
            'type' => 'Checkbox',
            'options' => [
                'label' => 'Activar catalogación asistida por IA', // @translate
                'info' => 'Si está desactivada, el re-catalogador manual funciona igual.', // @translate
            ],
            'attributes' => ['id' => LlmSettings::ENABLED],
        ]);

        $this->add([
            'name' => LlmSettings::PROVIDER,
            'type' => 'Select',
            'options' => [
                'label' => 'Proveedor LLM', // @translate
                'value_options' => [
                    LlmSettings::PROVIDER_ANTHROPIC => 'Anthropic (Messages API)',
                    LlmSettings::PROVIDER_OPENAI => 'OpenAI-compatible (local / OpenAI)',
                ],
            ],
            'attributes' => ['id' => LlmSettings::PROVIDER],
        ]);

        $this->add([
            'name' => LlmSettings::BASE_URL,
            'type' => 'Text',
            'options' => [
                'label' => 'Base URL del endpoint', // @translate
                'info' => 'Anthropic: https://api.anthropic.com. OpenAI-compatible: incluye /v1.', // @translate
            ],
            'attributes' => ['id' => LlmSettings::BASE_URL],
        ]);

        $this->add([
            'name' => LlmSettings::MODEL,
            'type' => 'Text',
            'options' => [
                'label' => 'Modelo', // @translate
                'info' => 'Identificador del modelo (p. ej. claude-opus-4-8 o el modelo local).', // @translate
            ],
            'attributes' => ['id' => LlmSettings::MODEL],
        ]);

        $this->add([
            'name' => LlmSettings::API_KEY,
            'type' => 'Password',
            'options' => [
                'label' => 'Clave API', // @translate
                'info' => 'Se guarda cifrada en settings; déjala en blanco para conservar la actual.', // @translate
            ],
            'attributes' => [
                'id' => LlmSettings::API_KEY,
                'autocomplete' => 'new-password',
            ],
        ]);

        $this->add([
            'name' => LlmSettings::CONTENT_TOKEN_CAP,
            'type' => 'Number',
            'options' => [
                'label' => 'Tope de tokens del contenido', // @translate
                'info' => 'Máximo de tokens del texto extraído enviado al LLM (≈ chars/4).', // @translate
            ],
            'attributes' => [
                'id' => LlmSettings::CONTENT_TOKEN_CAP,
                'min' => 500,
                'step' => 100,
            ],
        ]);
    }
}
