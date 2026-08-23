<?php

/**
 * OERManager — configuración del módulo (FASE 1: scaffolding).
 *
 * ACL: punto de extensión pendiente. Los privilegios propios de curación se
 * declararán programáticamente (Omeka\Permissions\Acl) en fases posteriores,
 * cuando exista la matriz rol×acción (PEND-007). En FASE 1 no se concede
 * ningún privilegio: solo el global admin ve la entrada de navegación.
 */

namespace OERManager;

use Laminas\Router\Http\Segment;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\IndexController::class => function ($container) {
                return new Controller\Admin\IndexController(
                    $container->get(Service\MasterViewQuery::class),
                    $container->get(Service\CurriculumSearch::class),
                    $container->get(Service\RecatalogService::class),
                    $container->get('Omeka\Logger'),
                    $container->get(Service\Ai\AiCataloguer::class),
                    $container->get(Service\Content\MediaSourceInterface::class),
                    $container->get(Service\Ai\EvaluationScorer::class),
                    $container->get('Omeka\Settings'),
                    $container->get('Omeka\Job\Dispatcher'),
                    $container->get(Service\Ai\ProposalStore::class),
                    $container->get(Service\ComputedFilter::class),
                    $container->get(Service\ResourceTypeVocab::class),
                    // TASK-029: la configuración se rinde desde el controlador,
                    // no desde Module. El manager (y no la instancia) porque es
                    // quien invoca `init()` del formulario.
                    $container->get('FormElementManager'),
                    $container->get(Service\IntegrityChecker::class),
                    $container->get(Service\ItemPanelData::class)
                );
            },
        ],
    ],
    'service_manager' => [
        'invokables' => [
            Service\IntegrityChecker::class => Service\IntegrityChecker::class,
            Service\ItemPanelData::class => Service\ItemPanelData::class,
            // Patrón de filtros computados (ADR-0013, D4).
            Service\ComputedFilter::class => Service\ComputedFilter::class,
            // Catalogación IA (TASK-010): núcleo puro sin dependencias.
            Service\Ai\PromptBuilder::class => Service\Ai\PromptBuilder::class,
            Service\Ai\ResponseParser::class => Service\Ai\ResponseParser::class,
            Service\Ai\EvaluationScorer::class => Service\Ai\EvaluationScorer::class,
        ],
        'factories' => [
            Service\MasterViewQuery::class => function ($container) {
                return new Service\MasterViewQuery($container->get('Omeka\ApiManager'));
            },
            // Vocabulario de tipos de recurso (D1). Dependencia BLANDA de
            // CustomVocab: no se declara en module.ini; si no está, degrada.
            Service\ResourceTypeVocab::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $api = $container->get('Omeka\ApiManager');
                return new Service\ResourceTypeVocab(
                    Service\GovernanceSettings::parseId(
                        $settings->get(Service\GovernanceSettings::RESOURCE_TYPE_VOCAB_ID)
                    ),
                    static function (int $id) use ($api): array {
                        return $api->read('custom_vocabs', $id)->getContent()->listValues();
                    }
                );
            },
            // Re-catalogador (TASK-004, RF-004/RF-005).
            Service\CurriculumSearch::class => function ($container) {
                return new Service\CurriculumSearch(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings')
                );
            },
            Service\RecatalogService::class => function ($container) {
                return new Service\RecatalogService(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings')
                );
            },

            // --- Catalogación IA-assistida (TASK-010, 4b) ---
            // Transporte HTTP del LLM (envuelve Laminas\Http\Client; sin SSRF).
            Service\Llm\HttpTransportInterface::class => function () {
                return new Service\Llm\LaminasHttpTransport();
            },
            // Cliente LLM activo según el proveedor configurado (ADR-0008).
            Service\Llm\LlmClientInterface::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $transport = $container->get(Service\Llm\HttpTransportInterface::class);
                $config = [
                    'api_key' => (string) $settings->get(Service\Llm\LlmSettings::API_KEY, ''),
                    'model' => (string) $settings->get(Service\Llm\LlmSettings::MODEL, ''),
                    'base_url' => (string) $settings->get(Service\Llm\LlmSettings::BASE_URL, ''),
                ];
                $provider = (string) $settings->get(
                    Service\Llm\LlmSettings::PROVIDER,
                    Service\Llm\LlmSettings::PROVIDER_ANTHROPIC
                );
                if (Service\Llm\LlmSettings::PROVIDER_OPENAI === $provider) {
                    return new Service\Llm\OpenAiCompatibleClient($transport, $config);
                }
                return new Service\Llm\AnthropicClient($transport, $config);
            },
            // Resolutor de términos (enumera candidatos acotados, adapta CurriculumSearch).
            Service\Ai\TermResolverInterface::class => function ($container) {
                return new Service\Ai\CurriculumTermResolver($container->get(Service\CurriculumSearch::class));
            },
            // Fuente de medios locales del item (sin URLs remotas).
            Service\Content\MediaSourceInterface::class => function ($container) {
                return new Service\Content\OmekaMediaSource(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\File\Store')
                );
            },
            // Extractor de contenido con el tope de tokens configurado (≈ chars/4).
            Service\Content\ContentExtractor::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $cap = (int) $settings->get(
                    Service\Llm\LlmSettings::CONTENT_TOKEN_CAP,
                    Service\Llm\LlmSettings::DEFAULT_CONTENT_TOKEN_CAP
                );
                return new Service\Content\ContentExtractor(['max_total_chars' => max(2000, $cap * 4)]);
            },
            // Perfil de inferencia compartido (paridad entre proveedores): el mismo
            // max_tokens + temperature en TODOS los pasos y por ambos adaptadores;
            // temperatura vacía = no enviar (default del proveedor).
            Service\Ai\CurricularClassifier::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\CurricularClassifier(
                    $container->get(Service\Llm\LlmClientInterface::class),
                    $container->get(Service\Ai\TermResolverInterface::class),
                    $container->get(Service\Ai\PromptBuilder::class),
                    $container->get(Service\Ai\ResponseParser::class),
                    Service\Llm\LlmSettings::parseMaxTokens($settings->get(Service\Llm\LlmSettings::MAX_TOKENS)),
                    Service\Llm\LlmSettings::parseTemperature($settings->get(Service\Llm\LlmSettings::TEMPERATURE))
                );
            },
            Service\Ai\TagClassifier::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\TagClassifier(
                    $container->get(Service\Llm\LlmClientInterface::class),
                    $container->get(Service\Ai\TermResolverInterface::class),
                    $container->get(Service\Ai\PromptBuilder::class),
                    $container->get(Service\Ai\ResponseParser::class),
                    Service\Llm\LlmSettings::parseMaxTokens($settings->get(Service\Llm\LlmSettings::MAX_TOKENS)),
                    Service\Llm\LlmSettings::parseTemperature($settings->get(Service\Llm\LlmSettings::TEMPERATURE))
                );
            },
            // Cliente LLM de extracción (barato, vision-capable; ADR-0011). Reusa
            // el mismo proveedor/endpoint/clave/transporte que el clasificador, con
            // EXTRACTION_MODEL (si está vacío, cae a MODEL). Clave de servicio propia:
            // convive con el cliente del clasificador (LlmClientInterface).
            'OERManager\Llm\ExtractionClient' => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $transport = $container->get(Service\Llm\HttpTransportInterface::class);
                $model = (string) $settings->get(Service\Llm\LlmSettings::EXTRACTION_MODEL, '');
                if ('' === $model) {
                    $model = (string) $settings->get(Service\Llm\LlmSettings::MODEL, '');
                }
                $config = [
                    'api_key' => (string) $settings->get(Service\Llm\LlmSettings::API_KEY, ''),
                    'model' => $model,
                    'base_url' => (string) $settings->get(Service\Llm\LlmSettings::BASE_URL, ''),
                ];
                $provider = (string) $settings->get(
                    Service\Llm\LlmSettings::PROVIDER,
                    Service\Llm\LlmSettings::PROVIDER_ANTHROPIC
                );
                if (Service\Llm\LlmSettings::PROVIDER_OPENAI === $provider) {
                    return new Service\Llm\OpenAiCompatibleClient($transport, $config);
                }
                return new Service\Llm\AnthropicClient($transport, $config);
            },
            // Destilador fiel (ADR-0011): ficha del recurso con el modelo de extracción.
            Service\Ai\ContextDistiller::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\ContextDistiller(
                    $container->get('OERManager\Llm\ExtractionClient'),
                    $container->get(Service\Ai\PromptBuilder::class),
                    Service\Llm\LlmSettings::parseMaxTokens($settings->get(Service\Llm\LlmSettings::MAX_TOKENS)),
                    Service\Llm\LlmSettings::parseTemperature($settings->get(Service\Llm\LlmSettings::TEMPERATURE))
                );
            },
            // Extractor de visión (ADR-0011): top-N imágenes + rescate de PDF escaneado
            // con el modelo de extracción. Apagado por defecto (VISION_ENABLED off):
            // egress de binarios a un tercero. El gating por capacidad del proveedor lo
            // resuelve el propio extractor (supportsImages()/supportsPdf()).
            Service\Content\MediaVisionExtractor::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Content\MediaVisionExtractor(
                    $container->get('OERManager\Llm\ExtractionClient'),
                    $container->get(Service\Ai\PromptBuilder::class),
                    (bool) $settings->get(Service\Llm\LlmSettings::VISION_ENABLED, false),
                    (int) $settings->get(
                        Service\Llm\LlmSettings::VISION_MAX_IMAGES,
                        Service\Llm\LlmSettings::DEFAULT_VISION_MAX_IMAGES
                    ),
                    maxTokens: Service\Llm\LlmSettings::parseMaxTokens(
                        $settings->get(Service\Llm\LlmSettings::MAX_TOKENS)
                    ),
                    temperature: Service\Llm\LlmSettings::parseTemperature(
                        $settings->get(Service\Llm\LlmSettings::TEMPERATURE)
                    ),
                    // Tope de envío del BINARIO del PDF (camino de respaldo): solo
                    // gobierna el bloque `document` nativo, que ya casi no se usa
                    // porque el PDF viaja rasterizado (TASK-026).
                    maxPdfBytes: Service\Llm\LlmSettings::parseVisionMaxPdfBytes(
                        $settings->get(Service\Llm\LlmSettings::VISION_MAX_PDF_BYTES)
                    ),
                    rasterizer: $container->get(Service\Content\PdfRasterizerInterface::class)
                );
            },
            // Rasterizador de PDF (TASK-026): convierte las primeras páginas en JPEG
            // con el mismo Imagick que usa Omeka para las derivadas. Sin la extensión
            // devuelve vacío y la visión cae al camino nativo del proveedor.
            Service\Content\PdfRasterizerInterface::class => function () {
                return new Service\Content\ImagickPdfRasterizer();
            },
            Service\Ai\AiCataloguer::class => function ($container) {
                return new Service\Ai\AiCataloguer(
                    $container->get(Service\Content\ContentExtractor::class),
                    $container->get(Service\Content\MediaVisionExtractor::class),
                    $container->get(Service\Ai\ContextDistiller::class),
                    $container->get(Service\Ai\CurricularClassifier::class),
                    $container->get(Service\Ai\TagClassifier::class)
                );
            },
            // Ensambla itemId → payload del navegador; lo reutiliza el AiProposeJob
            // en 2º plano (TASK-020).
            Service\Ai\ProposeRunner::class => function ($container) {
                return new Service\Ai\ProposeRunner(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\Ai\AiCataloguer::class),
                    $container->get(Service\Content\MediaSourceInterface::class)
                );
            },
            // Canal de estado/resultado del propose asíncrono (fichero privado, TASK-020).
            Service\Ai\ProposalStore::class => function ($container) {
                return new Service\Ai\ProposalStore(sys_get_temp_dir() . '/oer-manager-proposals');
            },
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'oer-manager' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/oer-manager[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'OERManager\Controller\Admin',
                                'controller' => Controller\Admin\IndexController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'OER Manager', // @translate
                'route' => 'admin/oer-manager',
                'resource' => Controller\Admin\IndexController::class,
                'pages' => [
                    // TASK-029: la configuración deja el listado de Módulos y
                    // cuelga de aquí, junto a la vista maestra que es donde se
                    // trabaja. `privilege` hace que la entrada solo se pinte a
                    // quien puede entrar (Supervisor y superior): sin él, un
                    // editor vería un enlace que le devuelve un 403.
                    [
                        'label' => 'Configuración', // @translate
                        'route' => 'admin/oer-manager',
                        'action' => 'config',
                        'resource' => Controller\Admin\IndexController::class,
                        'privilege' => 'config',
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    // Vista maestra (TASK-003/028): tipos propios bajo la clave `oer_items`,
    // que es independiente de la del browse nativo de items.
    'column_types' => [
        'invokables' => [
            'oerAlignmentStatus' => ColumnType\AlignmentStatus::class,
            'oerIsPublic' => ColumnType\IsPublic::class,
            'oerModified' => ColumnType\Modified::class,
            'oerId' => ColumnType\Id::class,
            'oerResourceTemplate' => ColumnType\ResourceTemplate::class,
            'oerCurricular' => ColumnType\Curricular::class,
            'oerGovernanceValue' => ColumnType\GovernanceValue::class,
        ],
        'factories' => [
            // Value necesita FormElementManager y ApiManager, igual que el del core.
            'oerValue' => function ($container) {
                return new ColumnType\Value(
                    $container->get('FormElementManager'),
                    $container->get('Omeka\ApiManager')
                );
            },
            'oerIntegrity' => function ($container) {
                return new ColumnType\Integrity(
                    $container->get(Service\IntegrityChecker::class)
                );
            },
        ],
    ],
    // Reequilibrio de ADR-0013 (TASK-028 rebanada 2): la tabla pasa de mostrar
    // el anclaje —que está al 100 %— a mostrar la gobernanza, que está vacía.
    // Ocho columnas contando el Título, que lo pinta la plantilla: es el tope
    // que TASK-027 §3 fijó para no forzar scroll horizontal en el admin.
    //
    // Los oerValue de lrmi:educationalLevel y schema:about salen (los fusiona
    // oerCurricular) y el de dcterms:rights también (lo sustituye la celda con
    // estado vacío). Siguen REGISTRADOS: un curador puede reactivarlos desde la
    // configuración nativa de columnas.
    //
    // OJO: column_defaults solo aplica a quien NO haya guardado su propia
    // selección. Quien la guardó tras la rebanada 1 conserva las columnas
    // viejas hasta que la reajuste.
    'column_defaults' => [
        'admin' => [
            'oer_items' => [
                // `oerAlignmentStatus` sale del juego por defecto: su señal la
                // absorbe `oerCurricular`, que ahora rotula «Anclaje curricular»
                // y funde el estado con los pares materia→curso. Sigue
                // REGISTRADA y su `statusFor()` alimenta el filtro de tres
                // estados, que no cambia.
                ['type' => 'oerIntegrity'],
                ['type' => 'oerCurricular'],
                [
                    'type' => 'oerGovernanceValue',
                    'property_term' => 'lrmi:learningResourceType',
                    'header' => 'Tipo de recurso', // @translate
                    'empty_label' => 'Sin tipo', // @translate
                ],
                [
                    'type' => 'oerGovernanceValue',
                    'property_term' => 'dcterms:rights',
                    'header' => 'Licencia', // @translate
                    'empty_label' => 'Sin licencia', // @translate
                ],
                ['type' => 'oerIsPublic'],
                ['type' => 'oerModified'],
            ],
        ],
    ],
    'browse_defaults' => [
        'admin' => [
            'oer_items' => ['sort_by' => 'modified', 'sort_order' => 'desc'],
        ],
    ],
];
