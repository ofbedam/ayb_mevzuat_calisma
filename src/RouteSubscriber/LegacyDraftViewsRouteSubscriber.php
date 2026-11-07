<?php

namespace Drupal\ayb_mevzuat_calisma\RouteSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/** Redirects legacy drafting Views to the single editor workspace. */
final class LegacyDraftViewsRouteSubscriber extends RouteSubscriberBase {

  /** {@inheritdoc} */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['view.ayb_mevzuat_taslaklari.page_1', 'view.ayb_taslak_maddeleri.page_1', 'view.ayb_taslak_gorusleri.page_1'] as $route_name) {
      if (!$collection->get($route_name)) {
        continue;
      }
      $route = clone $collection->get($route_name);
      $route->setDefault('_controller', '\\Drupal\\ayb_mevzuat_calisma\\Controller\\LegacyDraftViewsRedirectController::go');
      $route->setDefault('_title', 'Mevzuat Taslakları Çalışma Alanı');
      $route->setDefault('_view', NULL);
      $route->setRequirement('_permission', 'access ayb drafting workspace');
      $collection->add($route_name, $route);
    }
  }

}
