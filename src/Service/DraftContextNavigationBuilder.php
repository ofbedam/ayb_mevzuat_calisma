<?php

namespace Drupal\ayb_mevzuat_calisma\Service;

use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/** Builds one permission-aware navigation component for draft work routes. */
final class DraftContextNavigationBuilder {

  public function __construct(private readonly AccountProxyInterface $currentUser) {}

  /** Builds the global drafting hub header. */
  public function buildOverview(string $active = 'overview'): array {
    return [
      '#theme' => 'ayb_mevzuat_calisma_context_navigation',
      '#title' => t('Mevzuat taslakları'),
      '#subtitle' => t('Çalışma alanı'),
      '#home_url' => Url::fromRoute('ayb_mevzuat_calisma.draft_workspace')->toString(),
      '#tabs' => [$this->tab(t('Taslak listesi'), Url::fromRoute('ayb_mevzuat_calisma.draft_workspace'), $active === 'overview')],
      '#actions' => [],
      '#attributes' => ['class' => ['ayb-draft-context-navigation', 'ayb-draft-context-navigation--overview']],
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#cache' => ['contexts' => ['user.permissions', 'languages:language_interface'], 'max-age' => 0],
    ];
  }

  /** Builds navigation scoped to one draft and, optionally, one article. */
  public function build(NodeInterface $draft, string $active = 'workspace', ?NodeInterface $article = NULL, bool $actions = TRUE, ?NodeInterface $revisionTarget = NULL): array {
    if ($draft->bundle() !== 'mevzuat_taslagi' || !$draft->access('view')) {
      return [];
    }
    if (!$this->currentUser->hasPermission('access ayb drafting workspace')) {
      $public_tab = $active === 'snapshots' ? 'snapshots' : 'text';
      return $draft->isPublished() ? $this->buildPublic($draft, $public_tab, $article) : [];
    }
    $title = $article ? $article->label() : $draft->label();
    $model = $draft->hasField('field_taslak_calisma_modeli') && $draft->get('field_taslak_calisma_modeli')->value === 'madde_bazli'
      ? t('Madde bazlı') : t('Tam metin');
    $state = $this->stateLabel($draft);
    $tabs = [
      $this->tab(t('Çalışma'), Url::fromRoute($article ? 'ayb_mevzuat_calisma.workspace_article' : 'ayb_mevzuat_calisma.workspace_draft', $article ? ['draft' => $draft->id(), 'article' => $article->id()] : ['draft' => $draft->id()]), $active === 'workspace'),
    ];
    if ($this->currentUser->hasPermission('manage ayb draft suggestions') || $this->currentUser->hasPermission('administer ayb legal information')) {
      $tabs[] = $this->tab(t('Görüş ve öneriler'), Url::fromRoute('ayb_mevzuat_calisma.workspace_consolidation', ['draft' => $draft->id()]), $active === 'reviews');
    }
    if ($this->currentUser->hasPermission('access ayb drafting workspace')) {
      $tabs[] = $this->tab(t('Editoryal sürümler'), Url::fromRoute('ayb_mevzuat_calisma.snapshot_list', ['draft' => $draft->id()]), $active === 'snapshots');
    }
    $revision_target = $revisionTarget ?: ($article ?: $draft);
    if ($revision_target->access('view all revisions', $this->currentUser)) {
      $tabs[] = $this->tab(t('Revizyon geçmişi'), Url::fromRoute('entity.node.version_history', ['node' => $revision_target->id()]), $active === 'history');
    }

    $action_links = [];
    if ($actions && $draft->access('view')) {
      $preview = Link::createFromRoute(t('Önizleme'), 'ayb_mevzuat_calisma.draft_preview', ['node' => $draft->id()])->toRenderable();
      $preview['#attributes'] = ['class' => ['ayb-draft-context-navigation__action', 'ayb-draft-context-navigation__action--preview', 'use-ajax'], 'data-dialog-type' => 'modal', 'data-dialog-options' => json_encode(['modal' => TRUE, 'draggable' => FALSE, 'resizable' => FALSE, 'closeOnEscape' => TRUE, 'width' => 1120, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-preview-dialog']], JSON_UNESCAPED_SLASHES)];
      $action_links[] = $preview;
      if ($draft->access('update')) {
        $destination = Url::fromRoute(
          $article ? 'ayb_mevzuat_calisma.workspace_article' : 'ayb_mevzuat_calisma.workspace_draft',
          $article ? ['draft' => $draft->id(), 'article' => $article->id()] : ['draft' => $draft->id()]
        )->toString();
        $edit_url = $draft->toUrl('edit-form');
        $edit_url->setOption('query', ['destination' => $destination]);
        $action_links[] = Link::fromTextAndUrl(t('Taslağı düzenle'), $edit_url)->toRenderable();
      }
      if ($draft->isPublished()) {
        $action_links[] = Link::fromTextAndUrl(t('Yayımlanan sayfa'), $draft->toUrl())->toRenderable();
      }
    }

    $meta = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-context-navigation__meta']],
      'model' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-context-navigation__pill']], '#value' => $model],
      'state' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-context-navigation__pill', 'ayb-draft-context-navigation__pill--state']], '#value' => t('Aşama: @state', ['@state' => $state])],
    ];
    return [
      '#theme' => 'ayb_mevzuat_calisma_context_navigation',
      '#title' => $title,
      '#subtitle' => $article ? t('@draft · madde çalışması', ['@draft' => $draft->label()]) : t('AYB mevzuat çalışma alanı'),
      '#home_url' => Url::fromRoute('ayb_mevzuat_calisma.draft_workspace')->toString(),
      '#meta' => $meta,
      '#tabs' => $tabs,
      '#actions' => $action_links,
      '#attributes' => ['class' => ['ayb-draft-context-navigation'], 'data-draft-id' => (string) $draft->id()],
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft', 'core/drupal.dialog.ajax']],
      '#cache' => ['contexts' => ['user.permissions', 'languages:language_interface'], 'tags' => ['node:' . $draft->id()], 'max-age' => 0],
    ];
  }

  /** Builds the same navigation shell with public-safe destinations only. */
  public function buildPublic(NodeInterface $draft, string $active = 'text', ?NodeInterface $article = NULL): array {
    if ($draft->bundle() !== 'mevzuat_taslagi' || !$draft->isPublished() || !$draft->access('view')) {
      return [];
    }
    $tabs = [
      $this->tab(t('Metin'), Url::fromRoute('entity.node.canonical', ['node' => $draft->id()]), $active === 'text'),
      $this->tab(t('Yayımlanmış sürümler'), Url::fromRoute('ayb_mevzuat_calisma.public_snapshot_list', ['draft' => $draft->id()]), $active === 'snapshots'),
    ];
    $meta = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-context-navigation__meta']],
      'state' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-context-navigation__pill', 'ayb-draft-context-navigation__pill--state']], '#value' => t('Yayımlanmış')],
    ];
    return [
      '#theme' => 'ayb_mevzuat_calisma_context_navigation',
      '#title' => $draft->label(),
      '#subtitle' => $article ? t('Taslak içindeki madde') : t('Mevzuat taslağı'),
      '#home_url' => NULL,
      '#meta' => $meta,
      '#tabs' => $tabs,
      '#actions' => [],
      '#attributes' => ['class' => ['ayb-draft-context-navigation', 'ayb-draft-context-navigation--public'], 'data-draft-id' => (string) $draft->id()],
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#cache' => ['contexts' => ['user.permissions', 'languages:language_interface'], 'tags' => ['node:' . $draft->id()], 'max-age' => 0],
    ];
  }

  private function tab($label, Url $url, bool $active): array {
    $link = Link::fromTextAndUrl($label, $url)->toRenderable();
    $link['#attributes']['class'][] = 'ayb-draft-context-navigation__tab';
    if ($active) {
      $link['#attributes']['class'][] = 'is-active';
      $link['#attributes']['aria-current'] = 'page';
    }
    return $link;
  }

  private function stateLabel(NodeInterface $draft): string {
    if (!$draft->hasField('moderation_state') || $draft->get('moderation_state')->isEmpty()) {
      return (string) t('Belirtilmemiş');
    }
    $state = (string) $draft->get('moderation_state')->value;
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($draft);
    return $workflow && $workflow->getTypePlugin()->hasState($state)
      ? (string) $workflow->getTypePlugin()->getState($state)->label()
      : $state;
  }

}
