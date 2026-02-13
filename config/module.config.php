<?php

/**
 * @file
 * FirstMediaVisibility module configuration.
 */

declare(strict_types=1);

namespace FirstMediaVisibility;

use Laminas\Router\Http\Literal;
use FirstMediaVisibility\Controller\Admin\IndexController;

return [
  'navigation' => [
    'AdminModule' => [
      [
        'label' => 'First Media Visibility',
        'route' => 'admin/first-media-visibility',
        'resource' => IndexController::class,
        'privilege' => 'index',
      ],
    ],
  ],
  'controllers' => [
    'factories' => [
      IndexController::class => function ($services) {
        return new IndexController(
          $services->get('Omeka\\Connection'),
          $services->get('Omeka\\EntityManager')
        );
      },
    ],
  ],
  'router' => [
    'routes' => [
      'admin' => [
        'child_routes' => [
          'first-media-visibility' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/first-media-visibility',
              'defaults' => [
                '__NAMESPACE__' => 'FirstMediaVisibility\\Controller\\Admin',
                'controller' => IndexController::class,
                'action' => 'index',
              ],
            ],
            'may_terminate' => TRUE,
            'child_routes' => [
              'toggle' => [
                'type' => Literal::class,
                'options' => [
                  'route' => '/toggle',
                  'defaults' => [
                    'action' => 'toggle',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ],
  ],
  'view_manager' => [
    'template_path_stack' => [
      __DIR__ . '/../view',
    ],
  ],
];
