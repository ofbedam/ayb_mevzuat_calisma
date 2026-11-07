<?php

namespace Drupal\ayb_mevzuat_calisma\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/** Builds context-preserving breadcrumbs for legislative workspace routes. */
final class DraftWorkspaceBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  /** {@inheritdoc} */
  public function applies(RouteMatchInterface $route_match): bool {
    return in_array($route_match->getRouteName(), [
      'ayb_mevzuat_calisma.draft_workspace',
      'ayb_mevzuat_calisma.workspace_draft',
      'ayb_mevzuat_calisma.workspace_article',
      'ayb_mevzuat_calisma.snapshot_create',
      'ayb_mevzuat_calisma.snapshot_list',
      'ayb_mevzuat_calisma.snapshot_view',
      'ayb_mevzuat_calisma.snapshot_compare',
      'ayb_mevzuat_calisma.snapshot_action',
      'ayb_mevzuat_calisma.public_snapshot_list',
    ], TRUE);
  }

  /** {@inheritdoc} */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route', 'languages:language_interface'])
      ->addLink(Link::createFromRoute(t('Anasayfa'), '<front>'));

    if ($route_match->getRouteName() === 'ayb_mevzuat_calisma.draft_workspace') {
      return $breadcrumb->addLink(Link::createFromRoute(t('Mevzuat taslakları'), 'ayb_mevzuat_calisma.draft_workspace'));
    }

    $route_name = $route_match->getRouteName();
    $is_workspace = \Drupal::currentUser()->hasPermission('access ayb drafting workspace');
    $breadcrumb->addCacheContexts(['user.permissions']);
    if ($is_workspace) {
      $breadcrumb->addLink(Link::createFromRoute(t('Mevzuat taslakları'), 'ayb_mevzuat_calisma.draft_workspace'));
    }
    $draft = $route_match->getParameter('draft');
    $snapshot = NULL;
    if (in_array($route_name, ['ayb_mevzuat_calisma.snapshot_view', 'ayb_mevzuat_calisma.snapshot_action', 'ayb_mevzuat_calisma.snapshot_compare'], TRUE)) {
      $snapshot_id = match ($route_name) {
        'ayb_mevzuat_calisma.snapshot_view', 'ayb_mevzuat_calisma.snapshot_action' => (int) $route_match->getParameter('snapshot'),
        default => (int) $route_match->getParameter('first'),
      };
      $snapshot = \Drupal::database()->select('ayb_draft_snapshot', 's')->fields('s')->condition('snapshot_id', $snapshot_id)->execute()->fetchObject() ?: NULL;
      if ($snapshot) {
        $draft = \Drupal::entityTypeManager()->getStorage('node')->load($snapshot->draft_nid);
      }
    }
    if ($draft instanceof NodeInterface) {
      $breadcrumb
        ->addCacheableDependency($draft)
        ->addLink(Link::fromTextAndUrl($draft->label(), !$is_workspace && $draft->isPublished() && in_array($route_name, ['ayb_mevzuat_calisma.snapshot_view', 'ayb_mevzuat_calisma.snapshot_compare', 'ayb_mevzuat_calisma.public_snapshot_list'], TRUE) ? $draft->toUrl() : Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $draft->id()])));
    }

    if ($route_name === 'ayb_mevzuat_calisma.snapshot_list') {
      // Current page is the snapshot list; the draft link above is its parent.
      return $breadcrumb;
    }
    if ($draft instanceof NodeInterface && in_array($route_name, ['ayb_mevzuat_calisma.snapshot_create', 'ayb_mevzuat_calisma.snapshot_view', 'ayb_mevzuat_calisma.snapshot_compare', 'ayb_mevzuat_calisma.snapshot_action'], TRUE)) {
      $list_route = $is_workspace ? 'ayb_mevzuat_calisma.snapshot_list' : 'ayb_mevzuat_calisma.public_snapshot_list';
      if ($is_workspace || $draft->isPublished()) {
        $breadcrumb->addLink(Link::createFromRoute(t($is_workspace ? 'Sürüm geçmişi' : 'Yayımlanmış sürümler'), $list_route, ['draft' => $draft->id()]));
      }
      return $breadcrumb;
    }

    if ($route_name === 'ayb_mevzuat_calisma.public_snapshot_list') {
      return $breadcrumb;
    }

    $article = $route_match->getParameter('article');
    if ($article instanceof NodeInterface) {
      $breadcrumb
        ->addCacheableDependency($article)
        ->addLink(Link::fromTextAndUrl($article->label(), Url::fromRoute('ayb_mevzuat_calisma.workspace_article', [
          'draft' => $draft instanceof NodeInterface ? $draft->id() : 0,
          'article' => $article->id(),
        ])));
    }
    return $breadcrumb;
  }

}
