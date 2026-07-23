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
                    $container->get('Omeka\Settings')
                );
            },
        ],
    ],
    'service_manager' => [
        'invokables' => [
            Service\IntegrityChecker::class => Service\IntegrityChecker::class,
            // Catalogación IA (TASK-010): núcleo puro sin dependencias.
            Service\Ai\PromptBuilder::class => Service\Ai\PromptBuilder::class,
            Service\Ai\ResponseParser::class => Service\Ai\ResponseParser::class,
            Service\Ai\EvaluationScorer::class => Service\Ai\EvaluationScorer::class,
        ],
        'factories' => [
            Service\MasterViewQuery::class => function ($container) {
                return new Service\MasterViewQuery($container->get('Omeka\ApiManager'));
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
                    // Tope de envío de PDF a la visión (TASK-025): misma fuente
                    // que el tope de confirmación de AiCataloguer, para que un PDF
                    // confirmado no se caiga en silencio dentro del extractor.
                    maxPdfBytes: Service\Llm\LlmSettings::parseVisionMaxPdfBytes(
                        $settings->get(Service\Llm\LlmSettings::VISION_MAX_PDF_BYTES)
                    )
                );
            },
            Service\Ai\AiCataloguer::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\AiCataloguer(
                    $container->get(Service\Content\ContentExtractor::class),
                    $container->get(Service\Content\MediaVisionExtractor::class),
                    $container->get(Service\Ai\ContextDistiller::class),
                    $container->get(Service\Ai\CurricularClassifier::class),
                    $container->get(Service\Ai\TagClassifier::class),
                    Service\Llm\LlmSettings::parseVisionMaxPdfBytes(
                        $settings->get(Service\Llm\LlmSettings::VISION_MAX_PDF_BYTES)
                    )
                );
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
    // Vista maestra v1 (TASK-003, ADR-0005): indicador de alineamiento.
    'column_types' => [
        'invokables' => [
            'oerAlignmentStatus' => ColumnType\AlignmentStatus::class,
        ],
    ],
];
