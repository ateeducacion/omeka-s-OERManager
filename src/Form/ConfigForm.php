<?php

namespace OERManager\Form;

use Laminas\Form\Form;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\GovernanceSettings;
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

        $this->addGovernanceFields();
        $this->addLlmFields();
    }

    /**
     * Gobernanza del catálogo (ADR-0013, «sembrar, no poseer»): vocabularios de
     * licencia y tipo de recurso, plantilla REA y titular de derechos por defecto.
     *
     * Todo se identifica por **id, nunca por etiqueta**: las etiquetas cambian con
     * el idioma y con la edición del admin. Dejar un campo vacío no rompe nada —
     * el campo correspondiente degrada a texto libre (patrón de CurriculumSearch).
     */
    private function addGovernanceFields(): void
    {
        $this->add([
            'name' => GovernanceSettings::LICENCE_VOCAB_ID,
            'type' => 'Number',
            'options' => [
                'label' => 'CustomVocab de licencias (dcterms:rights)', // @translate
                'info' => 'ID del CustomVocab con las licencias admitidas. Si se deja vacío, '
                    . 'la licencia se edita como texto libre y no se puede normalizar.', // @translate
            ],
            'attributes' => [
                'id' => GovernanceSettings::LICENCE_VOCAB_ID,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->add([
            'name' => GovernanceSettings::RESOURCE_TYPE_VOCAB_ID,
            'type' => 'Number',
            'options' => [
                'label' => 'CustomVocab de tipos de recurso (lrmi:learningResourceType)', // @translate
                'info' => 'ID del CustomVocab con los tipos de recurso. El módulo NO lo crea: '
                    . 'se apunta al vocabulario que ya exista en la instalación.', // @translate
            ],
            'attributes' => [
                'id' => GovernanceSettings::RESOURCE_TYPE_VOCAB_ID,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->add([
            'name' => GovernanceSettings::REA_TEMPLATE_ID,
            'type' => 'Number',
            'options' => [
                'label' => 'Plantilla de los REA (resource_template)', // @translate
                'info' => 'ID de la plantilla propia de los REA. Sirve para distinguirla de '
                    . 'cualquier otra plantilla al comprobar la integridad: un REA con otra '
                    . 'plantilla se valida con la regla mínima y se avisa.', // @translate
            ],
            'attributes' => [
                'id' => GovernanceSettings::REA_TEMPLATE_ID,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->add([
            'name' => GovernanceSettings::DEFAULT_RIGHTS_HOLDER,
            'type' => 'Text',
            'options' => [
                'label' => 'Titular de derechos por defecto (dcterms:rightsHolder)', // @translate
                'info' => 'Valor que se propone al rellenar la ficha de un REA.', // @translate
            ],
            'attributes' => [
                'id' => GovernanceSettings::DEFAULT_RIGHTS_HOLDER,
                'maxlength' => GovernanceSettings::MAX_RIGHTS_HOLDER_LEN,
            ],
        ]);
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

        // Perfil de inferencia compartido (paridad entre proveedores): sin fijarlo,
        // cada proveedor aplica sus defaults y los resultados divergen entre
        // ejecuciones y entre proveedores (ficha destilada y selecciones).
        $this->add([
            'name' => LlmSettings::TEMPERATURE,
            'type' => 'Number',
            'options' => [
                'label' => 'Temperatura (ambos proveedores)', // @translate
                'info' => 'Se envía idéntica en todas las llamadas; 0–0.2 da resultados repetibles en '
                    . 'modelos que la aceptan (p. ej. Haiku 4.5, Sonnet 4.6). OJO: Sonnet 5, Opus 4.6+ '
                    . 'y Fable 5 la RECHAZAN con error 400 — con esos modelos déjala en blanco '
                    . '(= no enviar; ambos proveedores usan su default).', // @translate
            ],
            'attributes' => [
                'id' => LlmSettings::TEMPERATURE,
                'min' => 0,
                'max' => 2,
                'step' => 0.1,
            ],
        ]);

        $this->add([
            'name' => LlmSettings::MAX_TOKENS,
            'type' => 'Number',
            'options' => [
                'label' => 'Tope de tokens de la respuesta (max_tokens)', // @translate
                'info' => 'Tope de salida por llamada, idéntico en ambos proveedores. Si el modelo '
                    . 'razona o la ficha es larga y se agota, la respuesta llega truncada.', // @translate
            ],
            'attributes' => [
                'id' => LlmSettings::MAX_TOKENS,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->addVisionFields();
    }

    /**
     * Capa de contexto del LLM (ADR-0011): modelo de extracción/destilado barato y
     * visión (top-N imágenes + rescate de PDF escaneado). La visión está APAGADA por
     * defecto por privacidad: al activarla, los binarios de los medios salen hacia un
     * tercero (el proveedor LLM).
     */
    private function addVisionFields(): void
    {
        $this->add([
            'name' => LlmSettings::EXTRACTION_MODEL,
            'type' => 'Text',
            'options' => [
                'label' => 'Modelo de extracción/visión', // @translate
                'info' => 'Modelo barato (vision-capable) para destilar la ficha y describir imágenes; '
                    . 'si se deja en blanco, se reutiliza el modelo del clasificador.', // @translate
            ],
            'attributes' => ['id' => LlmSettings::EXTRACTION_MODEL],
        ]);

        $this->add([
            'name' => LlmSettings::VISION_ENABLED,
            'type' => 'Checkbox',
            'options' => [
                'label' => 'Activar visión (imágenes y PDF escaneado)', // @translate
                'info' => 'Envía los binarios de los medios al proveedor LLM. Apagada por defecto '
                    . '(privacidad). Requiere un proveedor/modelo con soporte de visión.', // @translate
            ],
            'attributes' => ['id' => LlmSettings::VISION_ENABLED],
        ]);

        $this->add([
            'name' => LlmSettings::VISION_MAX_IMAGES,
            'type' => 'Number',
            'options' => [
                'label' => 'Máximo de imágenes por recurso', // @translate
                'info' => 'Número de imágenes (las mayores) que se envían a visión por recurso.', // @translate
            ],
            'attributes' => [
                'id' => LlmSettings::VISION_MAX_IMAGES,
                'min' => 1,
                'step' => 1,
            ],
        ]);

        $this->add([
            'name' => LlmSettings::VISION_MAX_PDF_BYTES,
            'type' => 'Number',
            'options' => [
                'label' => 'Tope de PDF para visión (bytes)', // @translate
                'info' => 'Tamaño máximo del PDF que se ENVÍA al proveedor como '
                    . 'documento para visión (por defecto 33554432 = 32 MB, el '
                    . 'límite de Anthropic). Es distinto del tope de parseo interno '
                    . '(20 MB, guarda de seguridad, no configurable): un PDF entre '
                    . 'ambos topes se rescata por visión previa confirmación del '
                    . 'curador; por encima de este, no.', // @translate
            ],
            'attributes' => [
                'id' => LlmSettings::VISION_MAX_PDF_BYTES,
                'min' => 1,
                'step' => 1,
            ],
        ]);
    }
}
