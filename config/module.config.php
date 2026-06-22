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
                    $container->get(Service\RecatalogService::class)
                );
            },
        ],
    ],
    'service_manager' => [
        'invokables' => [
            Service\IntegrityChecker::class => Service\IntegrityChecker::class,
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
                return new Service\RecatalogService($container->get('Omeka\ApiManager'));
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
