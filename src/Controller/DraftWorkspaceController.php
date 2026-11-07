<?php

namespace Drupal\ayb_mevzuat_calisma\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Builds the editor-facing overview of legislative drafts. */
final class DraftWorkspaceController extends ControllerBase {

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('entity.repository'),
      $container->get('pager.manager'),
      $container->get('ayb_mevzuat_calisma.context_navigation'),
    );
  }

  public function __construct(
    protected $entityTypeManager,
    protected $dateFormatter,
    protected $entityRepository,
    protected PagerManagerInterface $pagerManager,
    protected \Drupal\ayb_mevzuat_calisma\Service\DraftContextNavigationBuilder $contextNavigation,
  ) {}

  /** Lists drafts with practical filters and status counts. */
  public function overview(Request $request): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $workflow = $this->entityTypeManager->getStorage('workflow')->load('ayb_taslak_editorial');
    $states = $workflow ? $workflow->getTypePlugin()->getStates() : [];
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslagi');
    $search_value = $request->query->get('q', '');
    $stage_value = $request->query->get('stage', '');
    $model_value = $request->query->get('model', '');
    $owner_value = $request->query->get('owner', '');
    $search = is_scalar($search_value) ? trim((string) $search_value) : '';
    $stage = is_scalar($stage_value) ? (string) $stage_value : '';
    $model = is_scalar($model_value) ? (string) $model_value : '';
    $owner = is_scalar($owner_value) ? (string) $owner_value : '';
    if ($search !== '') {
      $query->condition('title', $search, 'CONTAINS');
    }
    if ($stage !== '' && isset($states[$stage])) {
      $state_ids = $this->moderatedNodeIdsForState($stage);
      $query->condition('nid', $state_ids ?: [0], 'IN');
    }
    if (in_array($model, ['tam_metin', 'madde_bazli'], TRUE)) {
      $query->condition('field_taslak_calisma_modeli', $model);
    }
    if ($owner === 'me') {
      $query->condition('uid', $this->currentUser()->id());
    }

    $count_query = clone $query;
    $total = (int) $count_query->count()->execute();
    $page_size = 25;
    $pager = $this->pagerManager->createPager($total, $page_size);
    $ids = $query->sort('changed', 'DESC')->range($pager->getCurrentPage() * $page_size, $page_size)->execute();
    $drafts = $storage->loadMultiple($ids);
    // Resolve referenced legislation in one storage call instead of lazily
    // loading one entity per table row.
    $law_ids = [];
    foreach ($drafts as $draft) {
      if ($draft instanceof NodeInterface && $draft->hasField('field_taslak_mevzuat') && !$draft->get('field_taslak_mevzuat')->isEmpty()) {
        $law_ids[] = (int) $draft->get('field_taslak_mevzuat')->target_id;
      }
    }
    $laws = $law_ids ? $storage->loadMultiple(array_unique($law_ids)) : [];

    $counts = [];
    foreach ($states as $state_id => $state_definition) {
      $state_ids = $this->moderatedNodeIdsForState($state_id);
      $count_query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'mevzuat_taslagi');
      $count = $state_ids
        ? $count_query->condition('nid', $state_ids, 'IN')->count()->execute()
        : 0;
      $counts[$state_id] = (int) $count;
    }
    $all_count = (int) $storage->getQuery()->accessCheck(TRUE)->condition('type', 'mevzuat_taslagi')->count()->execute();

    $rows = [];
    foreach ($drafts as $draft) {
      if (!$draft instanceof NodeInterface) {
        continue;
      }
      $draft = $this->entityRepository->getTranslationFromContext($draft);
      $state_id = (string) $draft->get('moderation_state')->value;
      $state_label = isset($states[$state_id]) ? $states[$state_id]->label() : $this->t('Belirtilmemiş');
      $model_id = $draft->hasField('field_taslak_calisma_modeli') ? (string) $draft->get('field_taslak_calisma_modeli')->value : 'tam_metin';
      $model_label = $model_id === 'madde_bazli' ? $this->t('Madde bazlı') : $this->t('Tam metin');
      $law_id = $draft->hasField('field_taslak_mevzuat') && !$draft->get('field_taslak_mevzuat')->isEmpty()
        ? (int) $draft->get('field_taslak_mevzuat')->target_id
        : 0;
      $law = $law_id && isset($laws[$law_id]) ? $this->entityRepository->getTranslationFromContext($laws[$law_id]) : NULL;
      $law_link = $law ? $law->toLink()->toRenderable() : ['#plain_text' => $this->t('Yeni mevzuat taslağı')];
      $operations = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-workspace__operations']],
        'view' => Link::fromTextAndUrl($this->t('Çalışma alanını aç'), Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $draft->id()]))->toRenderable(),
      ];
      if ($draft->access('update')) {
        $operations['edit'] = Link::fromTextAndUrl($this->t('Düzenle'), $draft->toUrl('edit-form'))->toRenderable();
      }
      $rows[] = [
        'title' => ['data' => Link::fromTextAndUrl($draft->label(), Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $draft->id()]))->toRenderable(), 'class' => ['ayb-draft-workspace__title']],
        'law' => ['data' => $law_link],
        'model' => ['data' => ['#plain_text' => $model_label]],
        'stage' => ['data' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $state_label, '#attributes' => ['class' => ['ayb-draft-status', 'ayb-draft-status--' . $state_id]]]],
        'changed' => ['data' => ['#plain_text' => $this->dateFormatter->format($draft->getChangedTime(), 'short')]],
        'operations' => ['data' => $operations],
      ];
    }

    $status_links = [
      'all' => Link::fromTextAndUrl($this->t('Tümü (@count)', ['@count' => $all_count]), Url::fromRoute('ayb_mevzuat_calisma.draft_workspace'))->toRenderable(),
      'mine' => Link::fromTextAndUrl($this->t('Taslaklarım'), Url::fromRoute('ayb_mevzuat_calisma.draft_workspace', [], ['query' => ['owner' => 'me']]))->toRenderable(),
    ];
    foreach ($states as $state_id => $state_definition) {
      if (($counts[$state_id] ?? 0) === 0) {
        continue;
      }
      $status_links[$state_id] = Link::fromTextAndUrl(
        $this->t('@label (@count)', ['@label' => $state_definition->label(), '@count' => $counts[$state_id]]),
        Url::fromRoute('ayb_mevzuat_calisma.draft_workspace', [], ['query' => ['stage' => $state_id]])
      )->toRenderable();
    }

    $build = [
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-workspace']],
      'context_navigation' => $this->contextNavigation->buildOverview(),
      'intro' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-workspace__intro']],
        'description' => ['#plain_text' => $this->t('Taslakları, çalışma modelini ve inceleme aşamasını tek listeden takip edin.')],
      ],
      'actions' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-workspace__actions']]],
      'status_links' => ['#theme' => 'item_list', '#items' => $status_links, '#attributes' => ['class' => ['ayb-draft-workspace__status-links']]],
      'filters' => $this->formBuilder()->getForm('Drupal\\ayb_mevzuat_calisma\\Form\\DraftWorkspaceFilterForm'),
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Taslak'), $this->t('Bağlı mevzuat'), $this->t('Çalışma modeli'), $this->t('Aşama'), $this->t('Güncellendi'), $this->t('İşlemler')],
        '#rows' => $rows,
        '#empty' => $this->t('Bu filtrelerle eşleşen taslak bulunamadı.'),
        '#attributes' => ['class' => ['ayb-draft-workspace__table']],
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => [
        'contexts' => ['url.query_args', 'user', 'user.permissions', 'user.node_grants:view'],
        'tags' => ['node_list', 'config:workflows.workflow.ayb_taslak_editorial'],
      ],
    ];
    $create_access = $this->entityTypeManager->getAccessControlHandler('node')->createAccess('mevzuat_taslagi', NULL, [], TRUE);
    if ($create_access->isAllowed()) {
      $build['actions']['new'] = [
        '#type' => 'link',
        '#title' => $this->t('Yeni taslak oluştur'),
        '#url' => Url::fromRoute('node.add', ['node_type' => 'mevzuat_taslagi']),
        '#attributes' => ['class' => ['button', 'button--primary', 'ayb-draft-workspace__new']],
      ];
    }
    return $build;
  }

  /** Opens one draft in the dedicated legislative workspace. */
  public function draft(NodeInterface $draft): array {
    return $this->buildWorkspace($draft);
  }

  /** Opens one article while preserving its parent draft context. */
  public function article(NodeInterface $draft, NodeInterface $article): array {
    return $this->buildWorkspace($draft, $article);
  }

  /** Dynamic title for a draft workspace. */
  public function draftTitle(NodeInterface $draft): string {
    return (string) $this->t('@title çalışma alanı', ['@title' => $draft->label()]);
  }

  /** Dynamic title for an article workspace. */
  public function articleTitle(NodeInterface $draft, NodeInterface $article): string {
    return (string) $this->t('@title · @article', ['@title' => $draft->label(), '@article' => $this->articleLabel($article)]);
  }

  /** Checks access to a draft workspace. */
  public function draftAccess(NodeInterface $draft, AccountInterface $account): AccessResult {
    $bundle = AccessResult::allowedIf($draft->bundle() === 'mevzuat_taslagi')->addCacheableDependency($draft);
    $permission = AccessResult::allowedIfHasPermission($account, 'access ayb drafting workspace');
    $entity = $draft->access('view', $account, TRUE);
    return $bundle->andIf($permission)->andIf($entity)->cachePerPermissions();
  }

  /** Checks both the article and its parent relation. */
  public function articleAccess(NodeInterface $draft, NodeInterface $article, AccountInterface $account): AccessResult {
    $draft_access = $this->draftAccess($draft, $account);
    $valid = $article->bundle() === 'mevzuat_taslak_maddesi'
      && $article->hasField('field_taslak_madde_taslak')
      && (int) $article->get('field_taslak_madde_taslak')->target_id === (int) $draft->id();
    return $draft_access
      ->andIf(AccessResult::allowedIf($valid)->addCacheableDependency($article))
      ->andIf($article->access('view', $account, TRUE));
  }

  /** Restricts the consolidated review matrix to drafting reviewers. */
  public function consolidationAccess(NodeInterface $draft, AccountInterface $account): AccessResult {
    $role_access = AccessResult::allowedIfHasPermission($account, 'manage ayb draft suggestions')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer ayb legal information'));
    return $this->draftAccess($draft, $account)->andIf($role_access)->cachePerPermissions();
  }

  /** Lists opinions and suggestions for a draft in one review matrix. */
  public function consolidation(NodeInterface $draft, Request $request): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $rows = [];
    $article_ids = [];
    $opinion_ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_gorusu')
      ->condition('field_taslak_gorus_taslak', $draft->id())
      ->sort('changed', 'DESC')->execute();
    $suggestion_ids = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_onerisi')
      ->condition('field_tslk_oneri_taslak', $draft->id())
      ->sort('changed', 'DESC')->execute();
    $records = $storage->loadMultiple(array_unique(array_merge($opinion_ids, $suggestion_ids)));
    foreach ($records as $record) {
      if ($record->bundle() === 'mevzuat_taslak_gorusu' && $record->hasField('field_taslak_gorus_madde') && !$record->get('field_taslak_gorus_madde')->isEmpty()) {
        $article_ids[] = (int) $record->get('field_taslak_gorus_madde')->target_id;
      }
      if ($record->bundle() === 'mevzuat_taslak_onerisi' && $record->hasField('field_tslk_oneri_madde') && !$record->get('field_tslk_oneri_madde')->isEmpty()) {
        $article_ids[] = (int) $record->get('field_tslk_oneri_madde')->target_id;
      }
    }
    $articles = $article_ids ? $storage->loadMultiple(array_unique($article_ids)) : [];
    $decision = $request->query->get('decision', '');
    $query = is_scalar($decision) ? trim((string) $decision) : '';
    foreach ($records as $record) {
      $is_opinion = $record->bundle() === 'mevzuat_taslak_gorusu';
      $decision_value = '';
      $decision_label = 'İncelenmedi';
      if ($is_opinion && $record->hasField('field_taslak_gorus_sonucu') && !$record->get('field_taslak_gorus_sonucu')->isEmpty()) {
        $decision_value = (string) $record->get('field_taslak_gorus_sonucu')->value;
        $allowed = $record->get('field_taslak_gorus_sonucu')->getFieldDefinition()->getSetting('allowed_values') ?: [];
        $decision_label = $allowed[$decision_value] ?? $decision_value;
      }
      elseif (!$is_opinion && $record->hasField('field_tslk_oneri_durum') && !$record->get('field_tslk_oneri_durum')->isEmpty()) {
        $decision_value = (string) $record->get('field_tslk_oneri_durum')->value;
        $allowed = $record->get('field_tslk_oneri_durum')->getFieldDefinition()->getSetting('allowed_values') ?: [];
        $decision_label = $allowed[$decision_value] ?? $decision_value;
      }
      if ($query !== '' && $decision_value !== $query) {
        continue;
      }
      $article = NULL;
      $article_field = $is_opinion ? 'field_taslak_gorus_madde' : 'field_tslk_oneri_madde';
      if ($record->hasField($article_field) && !$record->get($article_field)->isEmpty()) {
        $article = $articles[(int) $record->get($article_field)->target_id] ?? NULL;
      }
      $author_field = $is_opinion ? 'field_taslak_gorus_sunan' : NULL;
      $author = $author_field && $record->hasField($author_field) ? (string) $record->get($author_field)->value : $record->getOwner()->getDisplayName();
      $text_field = $is_opinion ? 'field_taslak_gorus_metni' : 'field_tslk_oneri_yeni';
      $reason_field = $is_opinion ? 'field_taslak_gorus_cevabi' : 'field_tslk_oneri_gerekce';
      $rows[] = [
        'type' => ['data' => ['#plain_text' => $is_opinion ? 'Görüş' : 'Madde önerisi']],
        'article' => ['data' => $article ? $article->toLink()->toRenderable() : ['#plain_text' => 'Genel taslak']],
        'author' => ['data' => ['#plain_text' => $author ?: '—']],
        'text' => ['data' => ['#plain_text' => $this->plainFieldValue($record, $text_field)]],
        'decision' => ['data' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => htmlspecialchars($decision_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '#attributes' => ['class' => ['ayb-draft-review-status', 'ayb-draft-review-status--' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($decision_value ?: 'bekliyor'))]]]],
        'reason' => ['data' => ['#plain_text' => $this->plainFieldValue($record, $reason_field) ?: '—']],
        'operations' => ['data' => $record->toLink()->toRenderable()],
      ];
    }
    $build = [
      '#theme' => 'ayb_mevzuat_calisma_consolidation_matrix',
      '#attributes' => ['class' => ['ayb-draft-consolidation']],
      '#navigation' => $this->contextNavigation->build($draft, 'reviews', NULL, FALSE),
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#intro' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-consolidation__intro']], 'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Görüş ve öneri konsolidasyonu')], 'text' => ['#plain_text' => $this->t('Bu ekran, taslağa bağlı görüş ve madde önerilerini tek tabloda incelemenizi sağlar. Karar verilmemiş kayıtları filtreleyerek çalışma sırasını belirleyebilirsiniz.')]],
      '#filters' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-consolidation__filters']], 'all' => ['#type' => 'link', '#title' => $this->t('Tümü'), '#url' => Url::fromRoute('ayb_mevzuat_calisma.workspace_consolidation', ['draft' => $draft->id()]), '#attributes' => ['class' => ['button', 'button--small']]], 'open' => ['#type' => 'link', '#title' => $this->t('Açık / incelenmedi'), '#url' => Url::fromRoute('ayb_mevzuat_calisma.workspace_consolidation', ['draft' => $draft->id()], ['query' => ['decision' => 'acik']]), '#attributes' => ['class' => ['button', 'button--small']]]],
      '#bulk' => $this->formBuilder()->getForm('Drupal\\ayb_mevzuat_calisma\\Form\\DraftConsolidationBulkDecisionForm', $draft),
      '#table' => ['#type' => 'table', '#header' => [$this->t('Kayıt türü'), $this->t('Madde'), $this->t('Sunan'), $this->t('Metin / öneri'), $this->t('Karar'), $this->t('Gerekçe / cevap'), $this->t('İşlem')], '#rows' => $rows, '#empty' => $this->t('Bu taslak için görüş veya öneri bulunamadı.'), '#attributes' => ['class' => ['ayb-draft-consolidation__table']]],
      '#cache' => ['contexts' => ['url.query_args', 'user', 'user.permissions', 'user.node_grants:view'], 'tags' => ['node:' . $draft->id(), 'node_list']],
    ];
    CacheableMetadata::createFromObject($draft)->applyTo($build);
    return $build;
  }

  /** Returns a safe plain-text summary of a long-text field. */
  private function plainFieldValue(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $node->get($field)->value)));
  }

  /** Saves a same-level drag-and-drop reorder from the document tree. */
  public function reorder(NodeInterface $draft, Request $request): JsonResponse {
    if ($draft->bundle() !== 'mevzuat_taslagi' || !$draft->access('update')) {
      return new JsonResponse(['message' => $this->t('Bu taslağın sırası değiştirilemez.')], 403);
    }
    $token = (string) $request->query->get('_token', '');
    if (!$token || !\Drupal::service('csrf_token')->validate($token, 'ayb_mevzuat_calisma.reorder')) {
      return new JsonResponse(['message' => $this->t('Güvenlik doğrulaması başarısız oldu.')], 403);
    }
    $payload = json_decode($request->getContent(), TRUE);
    $record_id = is_array($payload) ? (int) ($payload['record'] ?? 0) : 0;
    $before_id = is_array($payload) ? (int) ($payload['before'] ?? 0) : 0;
    $target_parent_id = is_array($payload) ? (int) ($payload['parent'] ?? 0) : 0;
    if (!$record_id || (!$before_id && !$target_parent_id) || $record_id === $before_id || $record_id === $target_parent_id) {
      return new JsonResponse(['message' => $this->t('Geçerli bir sıralama hedefi seçilmedi.')], 422);
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $record = $storage->load($record_id);
    $before = $before_id ? $storage->load($before_id) : NULL;
    $target_parent = $target_parent_id ? $storage->load($target_parent_id) : NULL;
    if (!$record instanceof NodeInterface || ($before_id && !$before instanceof NodeInterface) || ($target_parent_id && !$target_parent instanceof NodeInterface) || $record->bundle() !== 'mevzuat_taslak_maddesi' || ($before && ($before->bundle() !== 'mevzuat_taslak_maddesi' || !$before->access('update'))) || ($target_parent && ($target_parent->bundle() !== 'mevzuat_taslak_maddesi' || !$target_parent->access('update'))) || !$record->access('update')) {
      return new JsonResponse(['message' => $this->t('Sıralanacak kayıt bulunamadı veya yetkiniz yok.')], 422);
    }
    if (!$this->belongsToDraft($record, $draft) || ($before && !$this->belongsToDraft($before, $draft)) || ($target_parent && !$this->belongsToDraft($target_parent, $draft))) {
      return new JsonResponse(['message' => $this->t('Kayıtlar aynı taslağa ait olmalıdır.')], 422);
    }
    $current_parent_id = $this->parentRecordId($record);
    if ($target_parent) {
      $target_parent_type = (string) ($target_parent->get('field_taslak_kayit_turu')->value ?: 'madde');
      $record_type = (string) ($record->get('field_taslak_kayit_turu')->value ?: 'madde');
      $allowed_parents = match ($record_type) {
        'kisim' => ['kitap'],
        'bolum' => ['kitap', 'kisim'],
        'alt_baslik' => ['kitap', 'kisim', 'bolum'],
        'madde_basligi' => ['kitap', 'kisim', 'bolum', 'alt_baslik'],
        'madde' => ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'],
        default => [],
      };
      if (!in_array($target_parent_type, $allowed_parents, TRUE) || !$this->isSafeParent($target_parent, $record)) {
        return new JsonResponse(['message' => $this->t('Bu kayıt türü seçilen bölümün altına taşınamaz.')], 422);
      }
      if ($before && $this->parentRecordId($before) !== $target_parent_id) {
        return new JsonResponse(['message' => $this->t('Yeni üst kayıt altındaki sıralama hedefi geçersiz.')], 422);
      }
    }
    else {
      $target_parent_id = $current_parent_id;
    }
    if ($before && $target_parent_id !== $this->parentRecordId($before)) {
      return new JsonResponse(['message' => $this->t('Kayıtlar aynı hiyerarşi seviyesinde olmalıdır.')], 422);
    }
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'mevzuat_taslak_maddesi')
      ->condition('field_taslak_madde_taslak', $draft->id());
    if ($target_parent_id) {
      $ids->condition('field_taslak_ust_kayit', $target_parent_id);
    }
    else {
      $ids->notExists('field_taslak_ust_kayit');
    }
    $siblings = $storage->loadMultiple($ids->execute());
    foreach ($siblings as $sibling) {
      if (!$sibling->access('update')) {
        return new JsonResponse(['message' => $this->t('Bu hiyerarşi seviyesindeki tüm kayıtları güncelleme yetkiniz bulunmuyor.')], 403);
      }
    }
    uasort($siblings, static function (NodeInterface $a, NodeInterface $b): int {
      $a_order = is_numeric($a->get('field_taslak_madde_sira')->value) ? (float) $a->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
      $b_order = is_numeric($b->get('field_taslak_madde_sira')->value) ? (float) $b->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
      return $a_order <=> $b_order ?: ((int) $a->id() <=> (int) $b->id());
    });
    $ordered = array_values(array_filter($siblings, static fn(NodeInterface $item): bool => (int) $item->id() !== $record_id));
    $target_index = $before_id ? array_search($before_id, array_map(static fn(NodeInterface $item): int => (int) $item->id(), $ordered), TRUE) : count($ordered);
    if ($target_index === FALSE) {
      return new JsonResponse(['message' => $this->t('Sıralama hedefi bulunamadı.')], 422);
    }
    if ($target_parent_id) {
      $record->set('field_taslak_ust_kayit', $target_parent_id);
    }
    elseif ($record->hasField('field_taslak_ust_kayit')) {
      $record->set('field_taslak_ust_kayit', []);
    }
    array_splice($ordered, $target_index, 0, [$record]);
    $transaction = \Drupal::database()->startTransaction();
    foreach ($ordered as $index => $item) {
      $new_order = ($index + 1) * 10;
      $parent_changed = (int) $item->id() === $record_id && $current_parent_id !== $target_parent_id;
      if (!$parent_changed && (int) $item->get('field_taslak_madde_sira')->value === $new_order) {
        continue;
      }
      $item->set('field_taslak_madde_sira', $new_order);
      $item->setNewRevision(TRUE);
      $item->setRevisionLogMessage($this->t('Çalışma ağacında belge sırası güncellendi.'));
      $item->save();
    }
    return new JsonResponse(['message' => $this->t('Belge sırası güncellendi.'), 'reload' => TRUE]);
  }

  /** Determines whether a draft article belongs to the selected draft. */
  private function belongsToDraft(NodeInterface $article, NodeInterface $draft): bool {
    return $article->hasField('field_taslak_madde_taslak') && (int) $article->get('field_taslak_madde_taslak')->target_id === (int) $draft->id();
  }

  /** Returns the explicit parent record ID, or zero for a root record. */
  private function parentRecordId(NodeInterface $article): int {
    return $article->hasField('field_taslak_ust_kayit') && !$article->get('field_taslak_ust_kayit')->isEmpty() ? (int) $article->get('field_taslak_ust_kayit')->target_id : 0;
  }

  /** Prevents self-parenting and cycles when a record moves under a node. */
  private function isSafeParent(NodeInterface $candidate, NodeInterface $record): bool {
    $seen = [];
    $cursor = $candidate;
    for ($depth = 0; $depth < 100; $depth++) {
      if (isset($seen[$cursor->id()]) || (int) $cursor->id() === (int) $record->id()) {
        return FALSE;
      }
      $seen[$cursor->id()] = TRUE;
      if (!$cursor->hasField('field_taslak_ust_kayit') || $cursor->get('field_taslak_ust_kayit')->isEmpty() || !($cursor->get('field_taslak_ust_kayit')->entity instanceof NodeInterface)) {
        return TRUE;
      }
      $cursor = $cursor->get('field_taslak_ust_kayit')->entity;
    }
    return FALSE;
  }

  /** Builds the three-panel workspace without replacing Drupal entity forms. */
  private function buildWorkspace(NodeInterface $draft, ?NodeInterface $selected = NULL): array {
    $draft = $this->entityRepository->getTranslationFromContext($draft);
    $children = $this->loadArticles($draft);
    if ($selected) {
      $selected = $this->entityRepository->getTranslationFromContext($selected);
    }
    $workspace_url = $selected
      ? Url::fromRoute('ayb_mevzuat_calisma.workspace_article', ['draft' => $draft->id(), 'article' => $selected->id()])
      : Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $draft->id()]);
    $destination = $workspace_url->toString();
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ayb-legislation-workspace'],
        'data-workspace-draft' => (string) $draft->id(),
        'data-workspace-reorder-url' => Url::fromRoute('ayb_mevzuat_calisma.workspace_reorder', ['draft' => $draft->id()])->toString(),
        'data-workspace-reorder-token' => \Drupal::service('csrf_token')->get('ayb_mevzuat_calisma.reorder'),
      ],
      '#attached' => ['library' => ['ayb_mevzuat_calisma/workspace']],
      'context_navigation' => $this->contextNavigation->build($draft, 'workspace', $selected),
      'layout' => [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__layout']],
        'tree' => $this->buildTree($draft, $children, $selected),
        'document' => $selected ? $this->buildArticlePanel($draft, $selected, $destination) : $this->buildDraftPanel($draft, $children, $destination),
        'inspector' => $this->buildInspector($draft, $children, $selected, $destination),
      ],
      '#cache' => [
        'contexts' => ['user', 'user.permissions', 'user.node_grants:view', 'languages:language_content'],
        'tags' => ['node:' . $draft->id(), 'node_list'],
      ],
    ];
    CacheableMetadata::createFromObject($draft)->applyTo($build);
    if ($selected) {
      CacheableMetadata::createFromObject($selected)->applyTo($build);
    }
    return $build;
  }

  /** Loads ordered article and heading records available to the user. */
  private function loadArticles(NodeInterface $draft): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_maddesi')
      ->condition('field_taslak_madde_taslak', $draft->id())
      ->sort('field_taslak_madde_sira', 'ASC')
      ->sort('nid', 'ASC')
      ->execute();
    // Editorial users need to see and edit the latest pending revision, even
    // while the public/default revision remains published.
    $articles = $ids ? $this->entityRepository->getActiveMultiple('node', $ids) : [];
    return array_values(array_map(fn(NodeInterface $article): NodeInterface => $this->entityRepository->getTranslationFromContext($article), $articles));
  }

  /** Builds the left hierarchical document tree. */
  private function buildTree(NodeInterface $draft, array $children, ?NodeInterface $selected): array {
    $hierarchy = ayb_mevzuat_calisma_hierarchy($draft, $children);
    $items = $this->buildTreeBranch($draft, $hierarchy, $selected, 0, 0, []);
    $tree = [
      '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__tree']],
      'heading' => [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__panel-heading']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Taslak yapısı')],
        'actions' => [
          '#type' => 'container', '#attributes' => ['class' => ['ayb-workspace-panel-actions']],
          'expand' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => '<span class="ayb-workspace-toggle__icon" aria-hidden="true">↔</span><span class="ayb-workspace-toggle__label">' . $this->t('Genişlet') . '</span>', '#attributes' => ['type' => 'button', 'class' => ['ayb-workspace-toggle', 'ayb-workspace-toggle--expand'], 'data-workspace-expand' => 'tree', 'aria-expanded' => 'false', 'title' => $this->t('Ağaç panelini genişlet')]],
          'toggle' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => '<span class="ayb-workspace-toggle__icon" aria-hidden="true">‹</span><span class="ayb-workspace-toggle__label">' . $this->t('Kapat') . '</span>', '#attributes' => ['type' => 'button', 'class' => ['ayb-workspace-toggle'], 'data-workspace-toggle' => 'tree', 'aria-expanded' => 'true', 'title' => $this->t('Paneli kapat')]],
        ],
      ],
      'home' => ['#type' => 'link', '#title' => $this->t('Taslak özeti'), '#url' => Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $draft->id()]), '#attributes' => ['class' => ['ayb-legislation-tree__home']]],
      'filter' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-legislation-tree__filter']],
        'label' => ['#type' => 'html_tag', '#tag' => 'label', '#value' => $this->t('Belge ağacında ara'), '#attributes' => ['for' => 'ayb-draft-tree-filter-' . $draft->id()]],
        'input' => ['#type' => 'textfield', '#title' => $this->t('Madde veya başlık ara'), '#title_display' => 'invisible', '#size' => 20, '#attributes' => ['id' => 'ayb-draft-tree-filter-' . $draft->id(), 'placeholder' => $this->t('Madde numarası, başlık veya kelime'), 'autocomplete' => 'off', 'data-ayb-tree-filter' => '1']],
        'status' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Yazdıkça eşleşen kayıtlar gösterilir. Aynı seviyede sıralamak veya yapısal bir bölümün altına taşımak için sürükleyin.'), '#attributes' => ['class' => ['ayb-legislation-tree__filter-status'], 'aria-live' => 'polite']],
      ],
      'items' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-tree__items']]] + $items,
    ];
    if (!$items) {
      $tree['empty'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Henüz bölüm veya madde eklenmedi.'), '#attributes' => ['class' => ['ayb-legislation-workspace__empty']]];
    }
    return $tree;
  }

  /** Recursively renders records beneath their explicit parent record. */
  private function buildTreeBranch(NodeInterface $draft, array $hierarchy, ?NodeInterface $selected, int $parent_id, int $level, array $visited): array {
    $items = [];
    foreach ($hierarchy['children'][$parent_id] ?? [] as $article) {
      if (isset($visited[(int) $article->id()])) {
        continue;
      }
      $branch_visited = $visited;
      $branch_visited[(int) $article->id()] = TRUE;
      $type = $article->hasField('field_taslak_kayit_turu') ? (string) ($article->get('field_taslak_kayit_turu')->value ?: 'madde') : 'madde';
      $classes = ['ayb-legislation-tree__item', 'ayb-legislation-tree__item--level-' . $level, 'ayb-legislation-tree__item--' . preg_replace('/[^a-z0-9_-]+/i', '-', $type)];
      if ($selected && (int) $selected->id() === (int) $article->id()) {
        $classes[] = 'is-active';
      }
      $link = ['#type' => 'link', '#title' => $this->articleLabel($article), '#url' => Url::fromRoute('ayb_mevzuat_calisma.workspace_article', ['draft' => $draft->id(), 'article' => $article->id()]), '#attributes' => ['class' => ['ayb-legislation-tree__link']]];
      $has_children = !empty($hierarchy['children'][(int) $article->id()]);
      if ($has_children || in_array($type, ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'], TRUE)) {
        $items['article_' . $article->id()] = [
          '#type' => 'details', '#title' => $this->articleLabel($article), '#open' => TRUE, '#attributes' => ['class' => $classes, 'draggable' => 'true', 'data-draft-record' => (string) $article->id(), 'data-draft-parent' => (string) $parent_id, 'data-draft-type' => $type],
          'record' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-tree__record']], 'link' => ['#type' => 'link', '#title' => '↗', '#url' => $link['#url'], '#attributes' => ['class' => ['ayb-legislation-tree__link', 'ayb-legislation-tree__link--jump'], 'aria-label' => $this->t('Bölüme git'), 'title' => $this->t('Bölüme git')]], 'state' => ['#type' => 'html_tag', '#tag' => 'small', '#value' => $this->stateLabel($article)]],
          'children' => $this->buildTreeBranch($draft, $hierarchy, $selected, (int) $article->id(), $level + 1, $branch_visited),
        ];
      }
      else {
        $items['article_' . $article->id()] = [
          '#type' => 'container', '#attributes' => ['class' => $classes, 'draggable' => 'true', 'data-draft-record' => (string) $article->id(), 'data-draft-parent' => (string) $parent_id, 'data-draft-type' => $type],
          'link' => $link,
          'state' => ['#type' => 'html_tag', '#tag' => 'small', '#value' => $this->stateLabel($article)],
        ];
      }
    }
    return $items;
  }

  /** Builds the centre panel for the draft itself. */
  private function buildDraftPanel(NodeInterface $draft, array $children, string $destination): array {
    $panel = [
      '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__document'], 'role' => 'main'],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Taslak özeti')],
      'description' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Künye, genel gerekçe ve taslağın yapısal durumunu buradan takip edin. Sol ağaçtan bir madde seçerek ayrıntılı çalışma ekranına geçin.')],
    ];
    foreach (['field_taslak_genel_gerekce' => $this->t('Genel gerekçe'), 'field_taslak_hukuki_dayanak' => $this->t('Hukukî dayanak'), 'field_taslak_amac_kapsam' => $this->t('Amaç ve kapsam')] as $field => $label) {
      if ($draft->hasField($field) && !$draft->get($field)->isEmpty() && $draft->get($field)->access('view')) {
        $panel[$field] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-document-section']], 'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $label], 'value' => $draft->get($field)->view(['label' => 'hidden'])];
      }
    }
    $panel['structure'] = [
      '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__summary-card']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $this->t('Belge yapısı')],
      'count' => ['#plain_text' => $this->formatPlural(count($children), '1 kayıt', '@count kayıt')],
    ];
    if ($draft->access('update')) {
      $panel['actions'] = [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__document-actions']],
        'edit' => ['#type' => 'link', '#title' => $this->t('Taslağı düzenle'), '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $draft->id()], ['query' => ['destination' => $destination]]), '#attributes' => ['class' => ['button', 'button--primary']]],
      ];
    }
    return $panel;
  }

  /** Builds the centre panel for one selected article. */
  private function buildArticlePanel(NodeInterface $draft, NodeInterface $article, string $destination): array {
    $type = (string) ($article->get('field_taslak_kayit_turu')->value ?: 'madde');
    $panel = [
      '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__document', 'ayb-legislation-workspace__document--article'], 'role' => 'main'],
      'context' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->recordTypeLabel($article), '#attributes' => ['class' => ['ayb-legislation-workspace__record-type']]],
      'heading' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->articleLabel($article)],
    ];
    $field_groups = [
      'identity' => [$this->t('Metin kimliği'), ['field_taslak_madde_numarasi', 'field_taslak_madde_basligi', 'field_taslak_bolum_basligi']],
      'text' => [$this->t('Madde metni'), ['field_taslak_madde_mevcut_metin', 'field_taslak_madde_oneri_metin', 'field_taslak_madde_nihai_metin']],
      'reason' => [$this->t('Gerekçe ve karşılaştırma'), ['field_taslak_madde_gerekcesi', 'field_taslak_madde_karsilastirma']],
    ];
    $has_current = $article->hasField('field_taslak_madde_mevcut_metin') && !$article->get('field_taslak_madde_mevcut_metin')->isEmpty() && $article->get('field_taslak_madde_mevcut_metin')->access('view');
    $has_proposed = $article->hasField('field_taslak_madde_oneri_metin') && !$article->get('field_taslak_madde_oneri_metin')->isEmpty() && $article->get('field_taslak_madde_oneri_metin')->access('view');
    if ($has_current && $has_proposed) {
      $field_groups['text'][1] = ['field_taslak_madde_nihai_metin'];
    }
    foreach ($field_groups as $key => [$label, $fields]) {
      $section = ['#type' => 'container', '#attributes' => ['id' => 'ayb-workspace-' . $key, 'class' => ['ayb-legislation-document-section', 'ayb-legislation-document-section--' . $key]], 'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $label]];
      $has_value = FALSE;
      foreach ($fields as $field) {
        if ($article->hasField($field) && !$article->get($field)->isEmpty() && $article->get($field)->access('view')) {
          $section[$field] = $article->get($field)->view(['label' => 'above']);
          $has_value = TRUE;
        }
      }
      if ($has_value) {
        $panel[$key] = $section;
      }
    }
    if ($has_current && $has_proposed) {
      $panel['side_by_side'] = [
        '#type' => 'details',
        '#title' => $this->t('Mevcut ve önerilen metni yan yana karşılaştır'),
        '#open' => TRUE,
        '#attributes' => ['id' => 'ayb-workspace-compare', 'class' => ['ayb-legislation-side-by-side']],
        'help' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Sol sütun mevcut metni, sağ sütun önerilen metni gösterir.'), '#attributes' => ['class' => ['ayb-legislation-side-by-side__help']]],
        'columns' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-legislation-side-by-side__columns']],
          'current' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ayb-legislation-side-by-side__column', 'ayb-legislation-side-by-side__column--current'], 'data-sync-scroll-group' => 'article-' . $article->id()],
            'title' => ['#type' => 'html_tag', '#tag' => 'h5', '#value' => $this->t('Mevcut metin')],
            'content' => $article->get('field_taslak_madde_mevcut_metin')->view(['label' => 'hidden']),
          ],
          'proposed' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ayb-legislation-side-by-side__column', 'ayb-legislation-side-by-side__column--proposed'], 'data-sync-scroll-group' => 'article-' . $article->id()],
            'title' => ['#type' => 'html_tag', '#tag' => 'h5', '#value' => $this->t('Önerilen metin')],
            'content' => $article->get('field_taslak_madde_oneri_metin')->view(['label' => 'hidden']),
          ],
        ],
      ];
    }
    if ($type === 'madde' && !isset($panel['text']) && !$has_current && !$has_proposed) {
      $panel['empty'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Bu madde için henüz metin girilmedi.'), '#attributes' => ['class' => ['ayb-legislation-workspace__empty']]];
    }
    if ($article->access('update')) {
      $panel['actions'] = [
        '#type' => 'container', '#weight' => -1, '#attributes' => ['class' => ['ayb-legislation-workspace__document-actions']],
        'quick' => ['#type' => 'link', '#title' => $this->t('Metni ve gerekçeyi düzenle'), '#url' => Url::fromUri('internal:#ayb-workspace-edit'), '#attributes' => ['class' => ['button', 'button--primary'], 'data-workspace-edit' => '1']],
        'edit' => ['#type' => 'link', '#title' => $this->t('Gelişmiş düzenleme'), '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $article->id()], ['query' => ['destination' => $destination]]), '#attributes' => ['class' => ['button'], 'title' => $this->t('Tüm alanları ve yönetim seçeneklerini aç')]],
      ];
      $panel['inline_edit'] = [
        '#type' => 'details',
        '#title' => $this->t('Metni bu sayfada düzenle'),
        '#open' => FALSE,
        '#attributes' => ['id' => 'ayb-workspace-edit', 'class' => ['ayb-legislation-inline-edit']],
        'help' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Metin ve gerekçe alanlarını bu sayfadan güncelleyebilirsiniz. Çalışma adı ve diğer teknik alanlar tam düzenleme ekranında tutulur.')],
        'form' => $this->buildInlineEditForm($article, $destination),
      ];
    }
    $navigation = [];
    foreach (['text' => $this->t('Metin'), 'reason' => $this->t('Gerekçe'), 'side_by_side' => $this->t('Karşılaştırma')] as $key => $label) {
      if (isset($panel[$key])) {
        $target = $key === 'side_by_side' ? 'compare' : $key;
        $navigation[$key] = ['#type' => 'link', '#title' => $label, '#url' => Url::fromUri('internal:#ayb-workspace-' . $target)];
      }
    }
    if ($navigation) {
      $panel['navigation'] = ['#type' => 'container', '#weight' => -2, '#attributes' => ['class' => ['ayb-workspace-document-nav'], 'role' => 'navigation', 'aria-label' => $this->t('Madde bölümleri')]] + $navigation;
    }
    return $panel;
  }

  /** Builds the focused edit form directly inside the article workspace. */
  private function buildInlineEditForm(NodeInterface $article, string $destination): array {
    $request = \Drupal::request();
    $request->attributes->set('ayb_mevzuat_calisma_quick_edit', TRUE);
    try {
      $form = \Drupal::service('entity.form_builder')->getForm($article, 'default');
    }
    finally {
      $request->attributes->remove('ayb_mevzuat_calisma_quick_edit');
    }
    $form['#action'] = Url::fromRoute('ayb_mevzuat_calisma.workspace_article', ['draft' => $article->get('field_taslak_madde_taslak')->target_id, 'article' => $article->id()], ['query' => ['destination' => $destination]])->toString();
    return $form;
  }

  /** Builds the right review and operation panel. */
  private function buildInspector(NodeInterface $draft, array $children, ?NodeInterface $selected, string $destination): array {
    $article_ids = array_map(static fn(NodeInterface $article): int => (int) $article->id(), $children);
    $suggestion_count = 0;
    if ($article_ids) {
      $suggestion_count = (int) $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)
        ->condition('type', 'mevzuat_taslak_onerisi')->condition('field_tslk_oneri_madde', $selected ? [(int) $selected->id()] : $article_ids, 'IN')->count()->execute();
    }
    $opinion_query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_gorusu')->condition('field_taslak_gorus_taslak', $draft->id());
    if ($selected) {
      $opinion_query->condition('field_taslak_gorus_madde', $selected->id());
    }
    $opinion_count = (int) $opinion_query->count()->execute();
    $revision_entity = $selected ?: $draft;
    $revision_count = count($this->entityTypeManager->getStorage('node')->revisionIds($revision_entity));
    $panel = [
      '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__inspector'], 'role' => 'complementary', 'aria-label' => $this->t('İnceleme paneli')],
      'heading' => [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-workspace__panel-heading']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('İnceleme ve işlemler')],
        'toggle' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => '<span class="ayb-workspace-toggle__icon" aria-hidden="true">›</span><span class="ayb-workspace-toggle__label">' . $this->t('Kapat') . '</span>', '#attributes' => ['type' => 'button', 'class' => ['ayb-workspace-toggle'], 'data-workspace-toggle' => 'inspector', 'aria-expanded' => 'true', 'title' => $this->t('Paneli kapat')]],
      ],
      'status' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-inspector-card']], 'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $this->t('Çalışma durumu')], 'value' => ['#plain_text' => $this->stateLabel($revision_entity)]],
      'counts' => [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-inspector-stats']],
        'suggestions' => ['#type' => 'container', 'number' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $suggestion_count], 'label' => ['#plain_text' => $this->t('Öneri')]],
        'opinions' => ['#type' => 'container', 'number' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $opinion_count], 'label' => ['#plain_text' => $this->t('Görüş')]],
        'revisions' => ['#type' => 'container', 'number' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $revision_count], 'label' => ['#plain_text' => $this->t('Sürüm')]],
      ],
      'links' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-legislation-inspector-card', 'ayb-legislation-inspector-card--links']], 'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $this->t('Kısayollar')]],
    ];
    $panel['links']['intro'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $selected ? $this->t('Seçili kayıt üzerinde yapmak istediğiniz işlemi seçin.') : $this->t('Taslağın yapısını, metnini ve inceleme sürecini buradan yönetin.'), '#attributes' => ['class' => ['ayb-legislation-inspector-card__help']]];
    if ($this->currentUser()->hasPermission('view all revisions')) {
      $panel['links']['history'] = ['#type' => 'link', '#title' => $this->t('Revizyon geçmişini aç'), '#url' => Url::fromRoute('entity.node.version_history', ['node' => $revision_entity->id()]), '#attributes' => ['class' => ['button', 'button--small', 'use-ajax', 'ayb-workspace-action'], 'data-dialog-type' => 'modal', 'data-dialog-options' => json_encode(['modal' => TRUE, 'draggable' => FALSE, 'resizable' => FALSE, 'closeOnEscape' => TRUE, 'width' => 1040, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-revision-dialog']])]];
      $draft_model = $draft->hasField('field_taslak_calisma_modeli') ? (string) $draft->get('field_taslak_calisma_modeli')->value : 'tam_metin';
      if ($selected || $draft_model === 'tam_metin') {
        $panel['links']['compare'] = ['#type' => 'link', '#title' => $this->t('Sürümleri karşılaştır'), '#url' => Url::fromRoute('ayb_mevzuat_calisma.revision_compare', ['node' => $revision_entity->id()]), '#attributes' => ['class' => ['button', 'button--small', 'use-ajax', 'ayb-workspace-action'], 'data-dialog-type' => 'modal', 'data-dialog-options' => json_encode(['modal' => TRUE, 'draggable' => FALSE, 'resizable' => FALSE, 'closeOnEscape' => TRUE, 'width' => 1040, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-revision-dialog']])]];
      }
    }
    if ($this->currentUser()->hasPermission('manage ayb draft suggestions') || $this->currentUser()->hasPermission('administer ayb legal information')) {
      $panel['links']['consolidation'] = ['#type' => 'link', '#title' => $this->t('Görüş ve önerileri toplu incele'), '#url' => Url::fromRoute('ayb_mevzuat_calisma.workspace_consolidation', ['draft' => $draft->id()]), '#attributes' => ['class' => ['button', 'button--small', 'ayb-workspace-action']]];
    }
    $transition_entity = $selected ?: $draft;
    if ($transition_entity->hasField('moderation_state') && $transition_entity->access('update')) {
      $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($transition_entity);
      $transitions = $workflow ? \Drupal::service('content_moderation.state_transition_validation')->getValidTransitions($transition_entity, $this->currentUser()) : [];
      $transitions = array_filter($transitions, static fn($transition): bool => $transition->to()->id() !== (string) $transition_entity->get('moderation_state')->value);
      if ($transitions) {
        $route = $selected ? 'ayb_mevzuat_calisma.workspace_article_moderation' : 'ayb_mevzuat_calisma.workspace_draft_moderation';
        $parameters = $selected ? ['draft' => $draft->id(), 'article' => $selected->id()] : ['draft' => $draft->id()];
        $panel['links']['moderation'] = ['#type' => 'link', '#title' => $this->t('Çalışma durumunu değiştir'), '#url' => Url::fromRoute($route, $parameters, ['query' => ['destination' => $destination]]), '#attributes' => ['class' => ['button', 'button--small', 'ayb-workspace-action']]];
      }
    }
    if ($draft->access('update') && $draft->hasField('field_taslak_calisma_modeli') && $draft->get('field_taslak_calisma_modeli')->value === 'madde_bazli') {
      $add_query = ['taslak' => $draft->id(), 'destination' => $destination];
      if ($selected && in_array((string) $selected->get('field_taslak_kayit_turu')->value, ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'], TRUE)) {
        $add_query['ust'] = $selected->id();
      }
      $panel['links']['add_article'] = ['#type' => 'link', '#title' => isset($add_query['ust']) ? $this->t('Bu bölümün altına kayıt ekle') : $this->t('Yeni bölüm veya madde ekle'), '#url' => Url::fromRoute('node.add', ['node_type' => 'mevzuat_taslak_maddesi'], ['query' => $add_query]), '#attributes' => ['class' => ['button', 'button--small', 'button--primary', 'ayb-workspace-action']]];
      $panel['links']['order'] = ['#type' => 'link', '#title' => $this->t('Çalışma sırasını düzenle'), '#url' => Url::fromRoute('ayb_mevzuat_calisma.draft_article_order', ['node' => $draft->id()]), '#attributes' => ['class' => ['button', 'button--small', 'ayb-workspace-action']]];
    }
    if ($selected && (string) ($selected->get('field_taslak_kayit_turu')->value ?: 'madde') === 'madde' && $selected->access('update')) {
      $panel['links']['add_suggestion'] = ['#type' => 'link', '#title' => $this->t('Bu madde için öneri ekle'), '#url' => Url::fromRoute('node.add', ['node_type' => 'mevzuat_taslak_onerisi'], ['query' => ['taslak' => $draft->id(), 'madde' => $selected->id(), 'destination' => $destination]]), '#attributes' => ['class' => ['button', 'button--small', 'ayb-workspace-action']]];
      $panel['links']['add_opinion'] = ['#type' => 'link', '#title' => $this->t('Bu madde için görüş ekle'), '#url' => Url::fromRoute('node.add', ['node_type' => 'mevzuat_taslak_gorusu'], ['query' => ['taslak' => $draft->id(), 'madde' => $selected->id(), 'destination' => $destination]]), '#attributes' => ['class' => ['button', 'button--small', 'ayb-workspace-action']]];
    }
    $panel['reviews'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-legislation-inspector-card', 'ayb-legislation-inspector-card--reviews']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $selected ? $this->t('Bu maddeye bağlı incelemeler') : $this->t('Taslağa bağlı incelemeler')],
    ];
    $suggestion_query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_onerisi')
      ->condition($selected ? 'field_tslk_oneri_madde' : 'field_tslk_oneri_taslak', $selected ? $selected->id() : $draft->id())
      ->sort('changed', 'DESC')->range(0, 8);
    $review_ids = $suggestion_query->execute();
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($review_ids) as $review) {
      $panel['reviews']['suggestion_' . $review->id()] = ['#type' => 'link', '#title' => $this->t('Öneri: @title', ['@title' => $review->label()]), '#url' => $review->toUrl('canonical')];
    }
    $opinion_list_query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_gorusu')->condition('field_taslak_gorus_taslak', $draft->id());
    if ($selected) {
      $opinion_list_query->condition('field_taslak_gorus_madde', $selected->id());
    }
    $opinion_ids = $opinion_list_query->sort('changed', 'DESC')->range(0, 8)->execute();
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($opinion_ids) as $review) {
      $panel['reviews']['opinion_' . $review->id()] = ['#type' => 'link', '#title' => $this->t('Görüş: @title', ['@title' => $review->label()]), '#url' => $review->toUrl('canonical')];
    }
    if (!$review_ids && !$opinion_ids) {
      $panel['reviews']['empty'] = ['#plain_text' => $this->t('Henüz bağlı öneri veya görüş bulunmuyor.')];
    }
    return $panel;
  }

  /** Returns the most useful human-readable article label. */
  private function articleLabel(NodeInterface $article): string {
    $type = $article->hasField('field_taslak_kayit_turu')
      ? (string) ($article->get('field_taslak_kayit_turu')->value ?: 'madde')
      : 'madde';
    $heading = $type === 'madde_basligi'
      ? ($article->hasField('field_taslak_madde_basligi') ? (string) $article->get('field_taslak_madde_basligi')->value : '')
      : (in_array($type, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE)
        ? ($article->hasField('field_taslak_bolum_basligi') ? (string) $article->get('field_taslak_bolum_basligi')->value : '')
        : ($article->hasField('field_taslak_madde_basligi') ? (string) $article->get('field_taslak_madde_basligi')->value : ''));
    $number = $article->hasField('field_taslak_madde_numarasi') ? (string) $article->get('field_taslak_madde_numarasi')->value : '';
    if ($type === 'madde' && $number !== '') {
      return trim((string) $this->t('Madde @number · @title', ['@number' => $number, '@title' => $heading !== '' ? $heading : $article->label()]));
    }
    return $heading !== '' ? $heading : $article->label();
  }

  /** Returns a translated record type. */
  private function recordTypeLabel(NodeInterface $article): string {
    if (!$article->hasField('field_taslak_kayit_turu')) {
      return (string) $this->t('Madde');
    }
    $value = (string) ($article->get('field_taslak_kayit_turu')->value ?: 'madde');
    $allowed = $article->get('field_taslak_kayit_turu')->getFieldDefinition()->getSetting('allowed_values');
    return (string) ($allowed[$value] ?? $this->t('Madde'));
  }

  /** Returns a translated moderation state, with a safe fallback. */
  private function stateLabel(NodeInterface $node): string {
    if (!$node->hasField('moderation_state') || $node->get('moderation_state')->isEmpty()) {
      return (string) $this->t('Durum belirtilmemiş');
    }
    $state = (string) $node->get('moderation_state')->value;
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($node);
    return $workflow && $workflow->getTypePlugin()->hasState($state) ? (string) $workflow->getTypePlugin()->getState($state)->label() : $state;
  }

  /** Returns moderated node IDs for the current default revision state. */
  private function moderatedNodeIdsForState(string $state): array {
    $ids = $this->entityTypeManager->getStorage('content_moderation_state')->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', 'node')
      ->condition('moderation_state', $state)
      ->condition('revision_default', 1)
      ->execute();
    return array_map('intval', array_values($ids));
  }

}
