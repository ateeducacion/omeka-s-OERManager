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

use Laminas\Router\Http\Literal;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Admin\IndexController::class => Controller\Admin\IndexController::class,
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
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/oer-manager',
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
    // Punto de extensión: column types propios de la vista maestra (TASK-003).
    'column_types' => [
        'invokables' => [
        ],
    ],
];
