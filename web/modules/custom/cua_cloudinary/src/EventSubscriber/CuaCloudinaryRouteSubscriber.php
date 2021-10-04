<?php

namespace Drupal\cua_cloudinary\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * CUA Users route subscriber.
 */
class CuaCloudinaryRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $admin_routes = [
      'entity.user.canonical',
      'user.login',
      'user.register',
      'user.pass',
      '<front>',
    ];

    foreach ($collection->all() as $key => $route) {
      if (in_array($key, $admin_routes)) {
        $route->setOption('_admin_route', TRUE);
      }

      // Hide taxonomy pages from unprivileged users...
      // if (strpos($route->getPath(), '/taxonomy/term') === 0) {
      // $route->setRequirement('_role', 'administrator');
      // }.
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = parent::getSubscribedEvents();

    // Use a lower priority than \Drupal\views\EventSubscriber\RouteSubscriber
    // to ensure the requirement will be added to its routes.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1300];

    return $events;
  }

}
