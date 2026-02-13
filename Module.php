<?php

declare(strict_types=1);

namespace FirstMediaVisibility;

use Laminas\EventManager\EventInterface;
use Omeka\Module\AbstractModule;
use Laminas\Mvc\MvcEvent;
use FirstMediaVisibility\Controller\Admin\IndexController;

/**
 * Module bootstrap for first-media visibility management.
 */
class Module extends AbstractModule {

  /**
   * Return module configuration.
   */
  public function getConfig(): array {
    return include __DIR__ . '/config/module.config.php';
  }

  /**
   * Return autoloader configuration.
   */
  public function getAutoloaderConfig(): array {
    return [
      'Laminas\\Loader\\StandardAutoloader' => [
        'namespaces' => [
          __NAMESPACE__ => __DIR__ . '/src',
        ],
      ],
    ];
  }

  /**
   * Configure ACL for admin access.
   */
  public function onBootstrap(EventInterface $event): void {
    if (!$event instanceof MvcEvent) {
      return;
    }

    $services = $event->getApplication()->getServiceManager();
    $acl = $services->get('Omeka\Acl');
    $acl->allow(['global_admin', 'site_admin'], [IndexController::class]);
  }

}
