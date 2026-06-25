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
            Service\Ai\CurricularClassifier::class => function ($container) {
                return new Service\Ai\CurricularClassifier(
                    $container->get(Service\Llm\LlmClientInterface::class),
                    $container->get(Service\Ai\TermResolverInterface::class),
                    $container->get(Service\Ai\PromptBuilder::class),
                    $container->get(Service\Ai\ResponseParser::class)
                );
            },
            Service\Ai\TagClassifier::class => function ($container) {
                return new Service\Ai\TagClassifier(
                    $container->get(Service\Llm\LlmClientInterface::class),
                    $container->get(Service\Ai\TermResolverInterface::class),
                    $container->get(Service\Ai\PromptBuilder::class),
                    $container->get(Service\Ai\ResponseParser::class)
                );
            },
            Service\Ai\AiCataloguer::class => function ($container) {
                return new Service\Ai\AiCataloguer(
                    $container->get(Service\Content\ContentExtractor::class),
                    $container->get(Service\Ai\CurricularClassifier::class),
                    $container->get(Service\Ai\TagClassifier::class)
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
