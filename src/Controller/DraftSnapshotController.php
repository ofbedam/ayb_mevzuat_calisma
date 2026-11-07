<?php

namespace Drupal\ayb_mevzuat_calisma\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Lists and compares immutable draft snapshots. */
final class DraftSnapshotController extends ControllerBase {

  public function __construct(private readonly Connection $database, private readonly \Drupal\ayb_mevzuat_calisma\Service\DraftSnapshotManager $manager, private readonly \Drupal\ayb_mevzuat_calisma\Service\DraftContextNavigationBuilder $contextNavigation) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('database'), $container->get('ayb_mevzuat_calisma.snapshot'), $container->get('ayb_mevzuat_calisma.context_navigation'));
  }

  public function editorialListTitle(NodeInterface $draft): string {
    return (string) $this->t('@draft · sürüm geçmişi', ['@draft' => $draft->label()]);
  }

  public function publicListTitle(NodeInterface $draft): string {
    return (string) $this->t('@draft · yayımlanmış sürümler', ['@draft' => $draft->label()]);
  }

  public function snapshotTitle(int $snapshot): string {
    $row = $this->loadSnapshot($snapshot);
    if (!$row) {
      return (string) $this->t('Mevzuat snapshotı');
    }
    $data = json_decode($row->payload, TRUE);
    return (string) $this->t('@draft · @snapshot', ['@draft' => $data['draft']['title'] ?? $this->t('Mevzuat taslağı'), '@snapshot' => $row->label]);
  }

  public function compareTitle(int $first, int $second): string {
    $row = $this->loadSnapshot($first) ?: $this->loadSnapshot($second);
    $data = $row ? json_decode($row->payload, TRUE) : [];
    return (string) $this->t('@draft · sürüm karşılaştırması', ['@draft' => $data['draft']['title'] ?? $this->t('Mevzuat taslağı')]);
  }

  public function editorialList(NodeInterface $draft): array {
    if ($draft->bundle() !== 'mevzuat_taslagi') {
      throw new NotFoundHttpException();
    }
    $rows = [];
    $snapshots = $this->manager->list($draft);
    foreach ($snapshots as $index => $snapshot) {
      $operations = [
        Link::fromTextAndUrl($this->t('Görüntüle'), Url::fromRoute('ayb_mevzuat_calisma.snapshot_view', ['snapshot' => $snapshot->snapshot_id]))->toRenderable(),
      ];
      // Results are newest first. The next row is this snapshot's previous
      // chronological version, so the newest snapshot gets the expected link.
      if (isset($snapshots[$index + 1])) {
        $older = $snapshots[$index + 1];
        $operations[] = Link::fromTextAndUrl($this->t('Öncekiyle karşılaştır'), Url::fromRoute('ayb_mevzuat_calisma.snapshot_compare', ['first' => $older->snapshot_id, 'second' => $snapshot->snapshot_id]))->toRenderable();
      }
      if ($this->currentUser()->hasPermission('publish ayb draft snapshots')) {
        $operations[] = Link::fromTextAndUrl($snapshot->visibility === 'public' ? $this->t('Geri çek') : $this->t('Kamuya aç'), Url::fromRoute('ayb_mevzuat_calisma.snapshot_action', ['snapshot' => $snapshot->snapshot_id, 'action' => $snapshot->visibility === 'public' ? 'withdraw' : 'publish']))->toRenderable();
      }
      $rows[] = [
        ['data' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $snapshot->label]],
        ['data' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-status', 'ayb-draft-snapshot-status--' . $snapshot->visibility]], '#value' => $snapshot->visibility === 'public' ? $this->t('Kamuya açık') : $this->t('Editoryal')]],
        ['data' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-date']], 'date' => ['#plain_text' => \Drupal::service('date.formatter')->format($snapshot->created, 'long')]]],
        ['data' => ['#plain_text' => trim((string) $snapshot->summary) !== '' ? $snapshot->summary : $this->t('Özet girilmedi.')]],
        ['data' => $operations],
      ];
    }
    $public_count = count(array_filter($snapshots, static fn($snapshot) => $snapshot->visibility === 'public'));
    $actions = [];
    if ($this->currentUser()->hasPermission('create ayb draft snapshots') && $draft->access('update')) {
      $actions['create'] = Link::createFromRoute($this->t('Editoryal sürüm oluştur'), 'ayb_mevzuat_calisma.snapshot_create', ['draft' => $draft->id()])->toRenderable();
    }
    if ($draft->isPublished()) {
      $actions['public'] = Link::createFromRoute($this->t('Yayımlanmış sürümleri görüntüle'), 'ayb_mevzuat_calisma.public_snapshot_list', ['draft' => $draft->id()])->toRenderable();
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-snapshot-list']],
      'navigation' => $this->contextNavigation->build($draft, 'snapshots', NULL, FALSE),
      'context' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-list__context']], 'draft' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $draft->label()], 'description' => ['#plain_text' => $this->t('Bu sayfada taslağın sabitlenmiş editoryal ve yayımlanmış sürümlerini inceleyebilir, karşılaştırabilir ve yetkiniz varsa yayımlayabilirsiniz. Snapshot oluşturmak madde durumlarını değiştirmez.')]],
      'actions' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-list__actions']], 'links' => $actions],
      'stats' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-list__stats']], 'total' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-stat']], 'value' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-stat__value']], '#value' => (string) count($snapshots)], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-stat__label']], '#value' => $this->t('Toplam sürüm')]], 'public' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-stat', 'ayb-draft-snapshot-stat--public']], 'value' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-stat__value']], '#value' => (string) $public_count], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-stat__label']], '#value' => $this->t('Yayımlanmış')]], 'editorial' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-stat', 'ayb-draft-snapshot-stat--editorial']], 'value' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-stat__value']], '#value' => (string) (count($snapshots) - $public_count)], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-draft-snapshot-stat__label']], '#value' => $this->t('Editoryal')]]],
      'table' => ['#type' => 'table', '#attributes' => ['class' => ['ayb-draft-snapshot-table']], '#header' => [$this->t('Sürüm'), $this->t('Yayın durumu'), $this->t('Oluşturulma'), $this->t('Değişiklik özeti'), $this->t('İşlemler')], '#rows' => $rows, '#empty' => $this->t('Henüz snapshot oluşturulmadı. İlk editoryal sürümü oluşturarak taslağın mevcut durumunu sabitleyin.')],
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#cache' => ['max-age' => 0],
    ];
    return $build;
  }

  /** Lists only public snapshots belonging to a published draft. */
  public function publicList(NodeInterface $draft): array {
    if ($draft->bundle() !== 'mevzuat_taslagi' || !$draft->isPublished()) {
      throw new NotFoundHttpException();
    }
    $rows = [];
    $snapshots = array_values(array_filter($this->manager->list($draft), static fn($snapshot) => $snapshot->visibility === 'public'));
    foreach ($snapshots as $index => $snapshot) {
      $operations = [Link::fromTextAndUrl($this->t('Bu sürümü görüntüle'), Url::fromRoute('ayb_mevzuat_calisma.snapshot_view', ['snapshot' => $snapshot->snapshot_id]))->toRenderable()];
      if (isset($snapshots[$index + 1])) {
        $older = $snapshots[$index + 1];
        $operations[] = Link::fromTextAndUrl($this->t('Öncekiyle karşılaştır'), Url::fromRoute('ayb_mevzuat_calisma.snapshot_compare', ['first' => $older->snapshot_id, 'second' => $snapshot->snapshot_id]))->toRenderable();
      }
      $rows[] = [
        $snapshot->label,
        \Drupal::service('date.formatter')->format($snapshot->created, 'long'),
        $snapshot->summary,
        ['data' => $operations],
      ];
    }
    return [
      '#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-list', 'ayb-draft-snapshot-list--public']],
      'navigation' => $this->contextNavigation->build($draft, 'snapshots', NULL, FALSE),
      'intro' => ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-snapshot-public-intro']], '#value' => $this->t('Bu listede @title için kamuya açık hale getirilmiş sabit sürümler yer alır.', ['@title' => $draft->label()])],
      'table' => ['#type' => 'table', '#header' => [$this->t('Sürüm'), $this->t('Yayımlanma tarihi'), $this->t('Değişiklik özeti'), $this->t('')], '#rows' => $rows, '#empty' => $this->t('Bu mevzuat için yayımlanmış eski sürüm bulunmuyor.')],
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#cache' => ['contexts' => ['url', 'languages:language_interface'], 'tags' => ['node:' . $draft->id(), 'ayb_draft_snapshot_list:' . $draft->id()], 'max-age' => 0],
    ];
  }

  public function view(int $snapshot): array {
    $row = $this->loadSnapshot($snapshot);
    if (!$row || $row->state !== 'active' || ($row->visibility !== 'public' && !$this->currentUser()->hasPermission('access ayb drafting workspace'))) {
      throw new NotFoundHttpException();
    }
    $data = json_decode($row->payload, TRUE, 512, JSON_THROW_ON_ERROR);
    $draft_fields = $data['fields'] ?? [];
    $draft_text = $draft_fields['field_taslak_nihai_metin'][0]['value'] ?? $draft_fields['field_taslak_metin'][0]['value'] ?? '';
    $draft_format = $draft_fields['field_taslak_nihai_metin'][0]['format'] ?? $draft_fields['field_taslak_metin'][0]['format'] ?? 'basic_html';
    $items = [];
    if ($draft_text !== '') {
      $items[] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-clean-text', 'ayb-snapshot-fulltext']], 'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Mevzuat metni')], 'text' => ['#type' => 'processed_text', '#text' => $draft_text, '#format' => $draft_format]];
    }
    foreach ($data['articles'] ?? [] as $article) {
      $f = $article['fields'];
      $kind = $f['field_taslak_kayit_turu'][0]['value'] ?? '';
      $title = $f['field_taslak_madde_basligi'][0]['value'] ?? $f['title'] ?? '';
      $num = $f['field_taslak_madde_numarasi'][0]['value'] ?? '';
      $heading = $kind === 'madde' ? trim($title) : trim($f['field_taslak_bolum_basligi'][0]['value'] ?? $title);
      $alignment_value = $draft_fields['field_taslak_baslik_hizalama'][0]['value'] ?? 'varsayilan';
      $alignment = match ($alignment_value) {
        'sol', 'left' => 'left',
        'orta', 'center' => 'center',
        default => in_array($kind, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE) ? 'center' : 'left',
      };
      $classes = ['ayb-draft-preview-article', 'ayb-draft-preview-article--' . preg_replace('/[^a-z0-9_-]+/i', '-', $kind), 'ayb-draft-preview-article--align-' . $alignment, 'ayb-snapshot-article'];
      $number_mode = $draft_fields['field_taslak_numara_gosterim'][0]['value'] ?? 'buyuk';
      $number_label = $kind === 'madde' && $num !== '' ? match ($number_mode) {
        'normal' => 'Madde ' . $num,
        'sadece_numara' => (string) $num,
        'gizli' => '',
        default => 'MADDE ' . $num . '-',
      } : '';
      $article_build = [
        '#type' => 'container',
        '#attributes' => ['class' => $classes, 'style' => 'margin-inline-start:' . min(6, (int) ($article['depth'] ?? 0)) . 'rem'],
        'number' => $number_label !== '' ? ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-preview-article__number']], '#value' => $number_label] : [],
        'heading' => $heading !== '' ? ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $heading] : [],
      ];
      // Render each saved rich-text field with its own stored text format. This
      // mirrors the normal preview and avoids losing Word-imported paragraph,
      // alignment, and inline formatting by flattening fields together.
      foreach ([
        'field_taslak_madde_oneri_metin' => $this->t('Önerilen metin'),
        'field_taslak_madde_nihai_metin' => $this->t('Nihai metin'),
      ] as $field_name => $field_label) {
        $item = $f[$field_name][0] ?? NULL;
        if (!$item || !isset($item['value']) || trim((string) $item['value']) === '') {
          continue;
        }
        $key = $field_name === 'field_taslak_madde_oneri_metin' ? 'proposed' : 'final';
        $article_build[$key] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-preview-field', 'ayb-snapshot-article__field', 'ayb-snapshot-article__field--' . $key]],
          'label' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $field_label],
          'value' => ['#type' => 'processed_text', '#text' => (string) $item['value'], '#format' => $item['format'] ?? 'basic_html'],
        ];
      }
      $items[] = $article_build;
    }
    $draft_title = (string) ($data['draft']['title'] ?? $this->t('Mevzuat taslağı'));
    array_unshift($items, ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-preview-paper__subtitle']], '#value' => $this->t('@label · @date', ['@label' => $row->label, '@date' => \Drupal::service('date.formatter')->format($row->created, 'long')])]);
    $paper = ['#theme' => 'ayb_mevzuat_calisma_draft_paper', '#attributes' => ['class' => ['ayb-draft-preview-paper', 'ayb-draft-preview-paper--snapshot']], '#title' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-preview-paper__title']], '#value' => $draft_title], '#body' => $items];
    $draft_entity = $this->entityTypeManager()->getStorage('node')->load($row->draft_nid);
    $navigation = $draft_entity instanceof NodeInterface
      ? $this->contextNavigation->build($draft_entity, 'snapshots', NULL, FALSE)
      : $this->snapshotNavigation($row);
    return ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-view']], 'summary' => ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-snapshot-summary']], '#value' => (string) $row->summary], 'navigation' => $navigation, 'paper' => $paper, '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']], '#cache' => ['max-age' => 0]];
  }

  public function compare(int $first, int $second): array {
    $a = $this->loadSnapshot($first); $b = $this->loadSnapshot($second);
    if (!$a || !$b || $a->draft_nid !== $b->draft_nid || ($this->currentUser()->isAnonymous() && ($a->visibility !== 'public' || $b->visibility !== 'public'))) {
      throw new NotFoundHttpException();
    }
    // Always present the older state on the left and the newer state on the
    // right, even when an old bookmarked URL supplies IDs in reverse order.
    if ((int) $a->created > (int) $b->created || ((int) $a->created === (int) $b->created && (int) $a->snapshot_id > (int) $b->snapshot_id)) {
      [$a, $b] = [$b, $a];
    }
    $left_payload = json_decode($a->payload, TRUE, 512, JSON_THROW_ON_ERROR);
    $right_payload = json_decode($b->payload, TRUE, 512, JSON_THROW_ON_ERROR);
    $full_document = \Drupal::request()->query->get('gorunum') === 'tam';
    $finished_document = \Drupal::request()->query->get('gorunum') === 'belge';
    $left = $left_payload['articles'] ?? [];
    $right = $right_payload['articles'] ?? [];
    $index = static fn(array $records): array => array_column($records, NULL, 'nid');
    $old = $index($left);
    $new = $index($right);
    // Follow the newer document order. Insert removed records before the next
    // surviving record from their old position, so deletions remain in context.
    $ids = array_column($right, 'nid');
    $new_ids = array_fill_keys($ids, TRUE);
    foreach ($left as $old_index => $record) {
      $id = $record['nid'] ?? NULL;
      if ($id === NULL || isset($new_ids[$id])) {
        continue;
      }
      $insert_at = count($ids);
      for ($next = $old_index + 1, $count = count($left); $next < $count; $next++) {
        $next_id = $left[$next]['nid'] ?? NULL;
        if ($next_id !== NULL && isset($new_ids[$next_id])) {
          $position = array_search($next_id, $ids, TRUE);
          if ($position !== FALSE) {
            $insert_at = $position;
          }
          break;
        }
      }
      array_splice($ids, $insert_at, 0, [$id]);
    }
    $rows = [];
    $document_counts = ['records' => 0, 'changed' => 0, 'added' => 0, 'removed' => 0];
    foreach ($ids as $id) {
      if ($finished_document) {
        continue;
      }
      $old_record = $old[$id] ?? NULL;
      $new_record = $new[$id] ?? NULL;
      $old_fields = $old_record['fields'] ?? [];
      $new_fields = $new_record['fields'] ?? [];
      $text_fields = ['title', 'field_taslak_madde_mevcut_metin', 'field_taslak_madde_nihai_metin', 'field_taslak_madde_oneri_metin', 'field_taslak_madde_gerekcesi', 'field_taslak_madde_basligi', 'field_taslak_madde_numarasi', 'field_taslak_bolum_basligi', 'field_taslak_kayit_turu'];
      $changes = [];
      $record_changed = !$old_record || !$new_record;
      foreach ($text_fields as $field) {
        // The entity title is stored as a scalar; field values are stored as
        // item arrays. Normalize both to the same comparison representation.
        $before_item = $field === 'title' ? ['value' => $old_fields['title'] ?? ''] : ($old_fields[$field][0] ?? []);
        $after_item = $field === 'title' ? ['value' => $new_fields['title'] ?? ''] : ($new_fields[$field][0] ?? []);
        $before = $before_item['value'] ?? '';
        $after = $after_item['value'] ?? '';
        $field_changed = (string) $before !== (string) $after || ($before_item['format'] ?? NULL) !== ($after_item['format'] ?? NULL);
        $record_changed = $record_changed || $field_changed;
        // The record title is already the card heading. In full-document mode
        // repeat it only when its value actually changed.
        if ($field_changed || ($full_document && $field !== 'title' && ((string) $before !== '' || (string) $after !== ''))) {
          $change = $this->snapshotChange($field, $before_item, $after_item);
          $change['unchanged'] = !$field_changed;
          $changes[] = $change;
        }
      }
      $structure_changed = ($old_record['parent'] ?? 0) !== ($new_record['parent'] ?? 0) || ($old_record['weight'] ?? 0) !== ($new_record['weight'] ?? 0);
      $record_changed = $record_changed || $structure_changed;
      if ($structure_changed) {
        $structure = $this->snapshotChange('structure',
          ['value' => (string) $this->t('Üst kayıt @parent, sıra @weight', ['@parent' => $old_record['parent'] ?? '—', '@weight' => $old_record['weight'] ?? '—'])],
          ['value' => (string) $this->t('Üst kayıt @parent, sıra @weight', ['@parent' => $new_record['parent'] ?? '—', '@weight' => $new_record['weight'] ?? '—'])]
        );
        $structure['unchanged'] = !$structure_changed;
        $changes[] = $structure;
      }
      if (!$full_document && !$record_changed) {
        continue;
      }
      $document_counts['records']++;
      $document_counts['changed'] += $record_changed && $old_record && $new_record ? 1 : 0;
      $document_counts['added'] += !$old_record ? 1 : 0;
      $document_counts['removed'] += !$new_record ? 1 : 0;
      $record_title = (string) ($new_fields['title'] ?? $old_fields['title'] ?? $this->t('Belge kaydı'));
      $number = $new_fields['field_taslak_madde_numarasi'][0]['value'] ?? $old_fields['field_taslak_madde_numarasi'][0]['value'] ?? '';
      $kind = $new_fields['field_taslak_kayit_turu'][0]['value'] ?? $old_fields['field_taslak_kayit_turu'][0]['value'] ?? '';
      $kind_labels = [
        'kitap' => $this->t('Kitap'),
        'kisim' => $this->t('Kısım'),
        'bolum' => $this->t('Bölüm'),
        'alt_baslik' => $this->t('Alt başlık'),
        'madde_basligi' => $this->t('Madde başlığı'),
        'madde' => $this->t('Madde'),
      ];
      $kind_label = $kind_labels[$kind] ?? $this->t('Belge kaydı');
      if ($kind === 'madde') {
        $record_title = (string) ($new_fields['field_taslak_madde_basligi'][0]['value'] ?? $old_fields['field_taslak_madde_basligi'][0]['value'] ?? $record_title);
        $record_label = trim((string) $this->t('Madde @number', ['@number' => $number !== '' ? $number : '—']) . ($record_title !== '' ? ' · ' . $record_title : ''));
      }
      else {
        $record_title = (string) ($new_fields['field_taslak_bolum_basligi'][0]['value'] ?? $old_fields['field_taslak_bolum_basligi'][0]['value'] ?? $record_title);
        $record_label = trim((string) $kind_label . ($record_title !== '' ? ' · ' . $record_title : ''));
      }
      $record_status = !$old_record ? $this->t('Eklendi') : (!$new_record ? $this->t('Kaldırıldı') : ($record_changed ? $this->t('Değişiklik var') : $this->t('Değişmedi')));
      $record_classes = ['ayb-snapshot-diff-item', 'ayb-snapshot-diff-item--record', 'ayb-snapshot-diff-item--type-' . preg_replace('/[^a-z0-9_-]+/i', '-', $kind ?: 'other')];
      $record_classes[] = $record_changed ? 'ayb-snapshot-diff-item--changed' : 'ayb-snapshot-diff-item--unchanged';
      if (!$old_record) {
        $record_classes[] = 'ayb-snapshot-diff-item--added';
      }
      elseif (!$new_record) {
        $record_classes[] = 'ayb-snapshot-diff-item--removed';
      }
      $status_class = !$old_record ? 'ayb-snapshot-diff-status--added' : (!$new_record ? 'ayb-snapshot-diff-status--removed' : ($record_changed ? 'ayb-snapshot-diff-status--changed' : 'ayb-snapshot-diff-status--unchanged'));
      $depth = min(8, max(0, (int) ($new_record['depth'] ?? $old_record['depth'] ?? 0)));
      $rows[] = [
        '#type' => 'container',
        '#attributes' => ['class' => $record_classes, 'style' => '--snapshot-depth: ' . $depth],
        'heading' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-snapshot-diff-item__heading']],
          'label' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $record_label],
          'status' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-snapshot-diff-status', $status_class]], '#value' => $record_status],
        ],
        'changes' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-snapshot-diff-fields']],
          'items' => array_map(static fn(array $change): array => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ayb-snapshot-diff-field', !empty($change['unchanged']) ? 'ayb-snapshot-diff-field--unchanged' : 'ayb-snapshot-diff-field--changed']],
            'label' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-diff-field__heading']], 'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $change['label']], 'status' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-snapshot-diff-status', !empty($change['unchanged']) ? 'ayb-snapshot-diff-status--unchanged' : 'ayb-snapshot-diff-status--changed']], '#value' => !empty($change['unchanged']) ? t('Değişmedi') : t('Değişti')]],
            'before' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-diff-before']], 'heading' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => t('Önceki sürüm')], 'value' => $change['before']],
            'after' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-diff-after']], 'heading' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => t('Yeni sürüm')], 'value' => $change['after']],
            'inline_diff' => $change['diff'] ?? [],
          ], $changes),
        ],
      ];
    }
    $draft_fields = ['field_taslak_metin', 'field_taslak_nihai_metin', 'field_taslak_genel_gerekce', 'field_taslak_hukuki_dayanak'];
    if (!$finished_document) {
      foreach ($draft_fields as $field) {
      $before_item = $left_payload['fields'][$field][0] ?? [];
      $after_item = $right_payload['fields'][$field][0] ?? [];
      $field_changed = ($before_item['value'] ?? '') !== ($after_item['value'] ?? '') || ($before_item['format'] ?? NULL) !== ($after_item['format'] ?? NULL);
      if ($field_changed || ($full_document && (!empty($before_item['value']) || !empty($after_item['value'])))) {
        $change = $this->snapshotChange($field, $before_item, $after_item);
        $rows[] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-snapshot-diff-item', 'ayb-snapshot-diff-item--draft', $field_changed ? 'ayb-snapshot-diff-item--changed' : 'ayb-snapshot-diff-item--unchanged']],
          'heading' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-diff-item__heading']], 'label' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $change['label']], 'status' => ['#type' => 'html_tag', '#tag' => 'span', '#attributes' => ['class' => ['ayb-snapshot-diff-status', $field_changed ? 'ayb-snapshot-diff-status--changed' : 'ayb-snapshot-diff-status--unchanged']], '#value' => $field_changed ? $this->t('Değişti') : $this->t('Değişmedi')]],
          'before' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-diff-before']], 'heading' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Önceki sürüm')], 'value' => $change['before']],
          'after' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-diff-after']], 'heading' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $this->t('Yeni sürüm')], 'value' => $change['after']],
          'inline_diff' => $change['diff'] ?? [],
        ];
      }
      }
    }
    $draft_entity = $this->entityTypeManager()->getStorage('node')->load($a->draft_nid);
    $draft_title = (string) ($right_payload['draft']['title'] ?? $left_payload['draft']['title'] ?? $this->t('Mevzuat taslağı'));
    $navigation = $draft_entity instanceof NodeInterface
      ? $this->contextNavigation->build($draft_entity, 'snapshots', NULL, FALSE)
      : $this->snapshotNavigation($a);
    $compare_url = static fn(array $query = []): Url => Url::fromRoute('ayb_mevzuat_calisma.snapshot_compare', ['first' => $a->snapshot_id, 'second' => $b->snapshot_id], $query ? ['query' => $query] : []);
    $view_switcher = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-snapshot-compare-modes'], 'role' => 'group', 'aria-label' => $this->t('Karşılaştırma görünümü')],
      'changes' => Link::fromTextAndUrl($this->t('Yalnız değişiklikler'), $compare_url())->toRenderable(),
      'full' => Link::fromTextAndUrl($this->t('Tüm belge'), $compare_url(['gorunum' => 'tam']))->toRenderable(),
      'finished' => Link::fromTextAndUrl($this->t('Tam metinleri yan yana'), $compare_url(['gorunum' => 'belge']))->toRenderable(),
    ];
    $view_switcher['changes']['#attributes']['class'][] = 'ayb-snapshot-compare-modes__link';
    $view_switcher['full']['#attributes']['class'][] = 'ayb-snapshot-compare-modes__link';
    $view_switcher['finished']['#attributes']['class'][] = 'ayb-snapshot-compare-modes__link';
    if (!$full_document && !$finished_document) {
      $view_switcher['changes']['#attributes']['class'][] = 'is-active';
      $view_switcher['changes']['#attributes']['aria-current'] = 'page';
    }
    elseif ($full_document) {
      $view_switcher['full']['#attributes']['class'][] = 'is-active';
      $view_switcher['full']['#attributes']['aria-current'] = 'page';
    }
    else {
      $view_switcher['finished']['#attributes']['class'][] = 'is-active';
      $view_switcher['finished']['#attributes']['aria-current'] = 'page';
    }
    $empty_message = $full_document
      ? $this->t('Bu snapshotlarda karşılaştırılabilir metin veya madde içeriği bulunmuyor.')
      : $this->t('Bu iki snapshot arasında metin veya madde değişikliği bulunamadı. Belgenin tamamını görmek için “Tüm belge” görünümünü seçin.');
    $overview = $full_document ? [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-snapshot-compare-overview'], 'aria-label' => $this->t('Belge karşılaştırma özeti')],
      'records' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-compare-overview__item']], 'value' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $document_counts['records']], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('belge öğesi')]],
      'changed' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-compare-overview__item', 'ayb-snapshot-compare-overview__item--changed']], 'value' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $document_counts['changed']], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('değişen')]],
      'added' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-compare-overview__item', 'ayb-snapshot-compare-overview__item--added']], 'value' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $document_counts['added']], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('eklenen')]],
      'removed' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-compare-overview__item', 'ayb-snapshot-compare-overview__item--removed']], 'value' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => (string) $document_counts['removed']], 'label' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('kaldırılan')]],
    ] : [];
    $finished_comparison = $finished_document ? [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-snapshot-finished-comparison']],
      'before' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-snapshot-finished-comparison__column', 'ayb-snapshot-finished-comparison__column--before']],
        'label' => $this->finishedComparisonLabel($this->t('Önceki metin'), $a->label, (int) $a->created, 'before'),
        'paper' => $this->finishedSnapshotDocument($left_payload),
      ],
      'after' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-snapshot-finished-comparison__column', 'ayb-snapshot-finished-comparison__column--after']],
        'label' => $this->finishedComparisonLabel($this->t('Yeni metin'), $b->label, (int) $b->created, 'after'),
        'paper' => $this->finishedSnapshotDocument($right_payload),
      ],
    ] : [];
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-snapshot-compare', $full_document ? 'ayb-draft-snapshot-compare--full' : ($finished_document ? 'ayb-draft-snapshot-compare--word-documents' : 'ayb-draft-snapshot-compare--changes-only')]],
      'navigation' => $navigation,
      'draft_title' => ['#type' => 'html_tag', '#tag' => 'h1', '#attributes' => ['class' => ['ayb-draft-snapshot-compare__title']], '#value' => $draft_title],
      'labels' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-snapshot-labels']], '#value' => $a->label . ' → ' . $b->label],
      'mode_switcher' => $view_switcher,
      'mode_help' => $full_document
        ? ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-snapshot-compare-mode-help']], '#value' => $this->t('Belgenin tüm başlıkları ve maddeleri gösteriliyor. Değişmeyen içerikler “Değişmedi” olarak işaretlenir.')]
        : ($finished_document ? ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-snapshot-compare-mode-help']], '#value' => $this->t('Her snapshot, Word çıktısındaki metin seçimiyle tek belge halinde gösterilir. Değişiklikleri renkli işaretlerle görmek için “Yalnız değişiklikler” görünümünü kullanın.')]
          : []),
      'overview' => $overview,
      'finished_comparison' => $finished_comparison,
      'changes' => $finished_document ? [] : ($rows ?: ['#type' => 'container', '#attributes' => ['class' => ['ayb-snapshot-no-diff']], '#plain_text' => $empty_message]),
      '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
      '#cache' => ['contexts' => ['url.query_args:gorunum'], 'max-age' => 0],
    ];
  }

  /** Labels one side of the finished-document comparison. */
  private function finishedComparisonLabel(string $side, string $snapshot_label, int $created, string $position): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-snapshot-finished-comparison__label', 'ayb-snapshot-finished-comparison__label--' . $position]],
      'side' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $side],
      'snapshot' => ['#type' => 'html_tag', '#tag' => 'span', '#value' => $this->t('@label · @date', ['@label' => $snapshot_label, '@date' => \Drupal::service('date.formatter')->format($created, 'short')])],
    ];
  }

  /** Builds a single, clean Word-like document from a frozen snapshot payload. */
  private function finishedSnapshotDocument(array $payload): array {
    $draft = $payload['draft'] ?? [];
    $fields = $payload['fields'] ?? [];
    $title = (string) ($draft['title'] ?? $this->t('Mevzuat taslağı'));
    $model = $fields['field_taslak_calisma_modeli'][0]['value'] ?? 'tam_metin';
    $body = [];

    if ($model === 'madde_bazli') {
      $number_mode = $fields['field_taslak_numara_gosterim'][0]['value'] ?? 'buyuk';
      $alignment_mode = $fields['field_taslak_baslik_hizalama'][0]['value'] ?? 'varsayilan';
      foreach ($this->orderSnapshotArticles($payload['articles'] ?? []) as $article) {
        $record_fields = $article['fields'] ?? [];
        $kind = $record_fields['field_taslak_kayit_turu'][0]['value'] ?? 'madde';
        $number = (string) ($record_fields['field_taslak_madde_numarasi'][0]['value'] ?? '');
        $heading = in_array($kind, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE)
          ? (string) ($record_fields['field_taslak_bolum_basligi'][0]['value'] ?? '')
          : (string) ($record_fields['field_taslak_madde_basligi'][0]['value'] ?? '');
        $alignment = match ($alignment_mode) {
          'sol', 'left' => 'left',
          'orta', 'center' => 'center',
          default => in_array($kind, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE) ? 'center' : 'left',
        };
        $classes = ['ayb-draft-preview-clean-article', 'ayb-draft-preview-clean-article--' . preg_replace('/[^a-z0-9_-]+/i', '-', $kind), 'ayb-draft-preview-clean-article--align-' . $alignment];
        $item = [
          '#type' => 'container',
          '#attributes' => ['class' => $classes, 'style' => 'margin-inline-start:' . min(6, max(0, (int) ($article['depth'] ?? 0))) . 'rem'],
        ];
        $number_label = $kind === 'madde' && $number !== '' ? match ($number_mode) {
          'normal' => (string) $this->t('Madde @number', ['@number' => $number]),
          'sadece_numara' => $number,
          'gizli' => '',
          default => (string) $this->t('MADDE @number-', ['@number' => $number]),
        } : '';
        if ($number_label !== '') {
          $item['number'] = ['#type' => 'html_tag', '#tag' => 'div', '#weight' => -1, '#attributes' => ['class' => ['ayb-draft-preview-clean-article__number']], '#value' => $number_label];
        }
        if ($heading !== '') {
          $item['heading'] = ['#type' => 'html_tag', '#tag' => 'h3', '#weight' => -2, '#value' => $heading];
        }
        foreach (['field_taslak_madde_nihai_metin', 'field_taslak_madde_oneri_metin', 'field_taslak_madde_mevcut_metin'] as $text_field) {
          $text_item = $record_fields[$text_field][0] ?? NULL;
          if ($text_item && !empty($text_item['value'])) {
            $item['text'] = [
              '#type' => 'container',
              '#attributes' => ['class' => ['ayb-draft-preview-clean-article__text']],
              'value' => ['#type' => 'processed_text', '#text' => (string) $text_item['value'], '#format' => $text_item['format'] ?? 'basic_html'],
            ];
            break;
          }
        }
        if ($heading !== '' || !empty($item['text'])) {
          $body[] = $item;
        }
      }
    }
    else {
      foreach (['field_taslak_nihai_metin', 'field_taslak_metin', 'field_taslak_mevcut_metin'] as $text_field) {
        $text_item = $fields[$text_field][0] ?? NULL;
        if ($text_item && !empty($text_item['value'])) {
          $body[] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ayb-draft-preview-clean-text']],
            'value' => ['#type' => 'processed_text', '#text' => (string) $text_item['value'], '#format' => $text_item['format'] ?? 'basic_html'],
          ];
          break;
        }
      }
    }

    if (!$body) {
      $body[] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-preview-empty']], '#value' => $this->t('Bu snapshotta Word görünümünde karşılaştırılabilecek metin bulunmuyor.')];
    }
    return [
      '#theme' => 'ayb_mevzuat_calisma_draft_paper',
      '#attributes' => ['class' => ['ayb-draft-preview-paper', 'ayb-draft-preview-paper--clean', 'ayb-draft-preview-paper--snapshot', 'ayb-draft-preview-paper--comparison']],
      '#title' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-preview-paper__title']], '#value' => $title],
      '#body' => $body,
    ];
  }

  /** Orders snapshot article rows by their parent hierarchy and saved weight. */
  private function orderSnapshotArticles(array $articles): array {
    $by_id = [];
    foreach ($articles as $article) {
      if (isset($article['nid'])) {
        $by_id[(int) $article['nid']] = $article;
      }
    }
    $children = [];
    foreach ($by_id as $id => $article) {
      $parent = (int) ($article['parent'] ?? 0);
      if ($parent && !isset($by_id[$parent])) {
        $parent = 0;
      }
      $children[$parent][] = $id;
    }
    foreach ($children as &$siblings) {
      usort($siblings, static function (int $left, int $right) use ($by_id): int {
        $weight = ($by_id[$left]['weight'] ?? 0) <=> ($by_id[$right]['weight'] ?? 0);
        return $weight ?: ($left <=> $right);
      });
    }
    unset($siblings);
    $ordered = [];
    $visited = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$ordered, &$visited, $children, $by_id): void {
      foreach ($children[$parent] ?? [] as $id) {
        if (isset($visited[$id])) {
          continue;
        }
        $visited[$id] = TRUE;
        $record = $by_id[$id];
        $record['depth'] = min(8, $depth);
        $ordered[] = $record;
        $walk($id, $depth + 1);
      }
    };
    $walk(0, 0);
    // Keep malformed/cyclic historical records visible instead of dropping them.
    foreach ($by_id as $id => $record) {
      if (!isset($visited[$id])) {
        $record['depth'] = min(8, max(0, (int) ($record['depth'] ?? 0)));
        $ordered[] = $record;
      }
    }
    return $ordered;
  }

  /** Provides clear return links from snapshot detail and comparison pages. */
  private function snapshotNavigation(object $snapshot): array {
    $links = [];
    if ($this->currentUser()->hasPermission('access ayb drafting workspace')) {
      $links['list'] = Link::createFromRoute($this->t('Sürüm geçmişine dön'), 'ayb_mevzuat_calisma.snapshot_list', ['draft' => $snapshot->draft_nid])->toRenderable();
      $links['draft'] = Link::createFromRoute($this->t('Taslak çalışma alanına dön'), 'ayb_mevzuat_calisma.workspace_draft', ['draft' => $snapshot->draft_nid])->toRenderable();
    }
    else {
      $draft = $this->entityTypeManager()->getStorage('node')->load($snapshot->draft_nid);
      if ($draft && $draft->isPublished()) {
        $links['list'] = Link::createFromRoute($this->t('Yayımlanmış sürümlere dön'), 'ayb_mevzuat_calisma.public_snapshot_list', ['draft' => $snapshot->draft_nid])->toRenderable();
        $links['draft'] = Link::fromTextAndUrl($this->t('Taslağa dön'), $draft->toUrl())->toRenderable();
      }
    }
    return ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-snapshot-navigation']], 'links' => $links];
  }

  public function access(NodeInterface $draft): AccessResult {
    return AccessResult::allowedIf($draft->bundle() === 'mevzuat_taslagi' && $draft->access('view'))->addCacheableDependency($draft);
  }

  public function snapshotAccess(int $snapshot): AccessResult {
    $row = $this->loadSnapshot($snapshot);
    if (!$row || $row->state !== 'active') {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    if ($row->visibility === 'public') {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    return AccessResult::allowedIf($this->currentUser()->hasPermission('access ayb drafting workspace'))->cachePerPermissions()->setCacheMaxAge(0);
  }

  public function publicListAccess(NodeInterface $draft): AccessResult {
    return AccessResult::allowedIf($draft->bundle() === 'mevzuat_taslagi' && $draft->isPublished() && $draft->access('view'))->addCacheableDependency($draft);
  }

  public function compareAccess(int $first, int $second): AccessResult {
    $a = $this->loadSnapshot($first); $b = $this->loadSnapshot($second);
    if (!$a || !$b || $a->draft_nid !== $b->draft_nid || $a->state !== 'active' || $b->state !== 'active') {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    $allowed = ($a->visibility === 'public' && $b->visibility === 'public') || $this->currentUser()->hasPermission('access ayb drafting workspace');
    return AccessResult::allowedIf($allowed)->cachePerPermissions()->setCacheMaxAge(0);
  }

  private function loadSnapshot(int $id): ?object {
    return $this->database->select('ayb_draft_snapshot', 's')->fields('s')->condition('snapshot_id', $id)->execute()->fetchObject() ?: NULL;
  }

  /** Builds a safe, format-aware before/after value for a snapshot diff. */
  private function snapshotChange(string $field, array $before, array $after): array {
    $labels = [
      'field_taslak_madde_nihai_metin' => $this->t('Nihai madde metni'),
      'field_taslak_madde_oneri_metin' => $this->t('Önerilen madde metni'),
      'field_taslak_madde_gerekcesi' => $this->t('Madde gerekçesi'),
      'title' => $this->t('Kayıt başlığı'),
      'field_taslak_madde_basligi' => $this->t('Madde başlığı'),
      'field_taslak_madde_numarasi' => $this->t('Madde numarası'),
      'field_taslak_bolum_basligi' => $this->t('Bölüm başlığı'),
      'field_taslak_kayit_turu' => $this->t('Kayıt türü'),
      'field_taslak_metin' => $this->t('Taslak metni'),
      'field_taslak_nihai_metin' => $this->t('Nihai mevzuat metni'),
      'field_taslak_genel_gerekce' => $this->t('Genel gerekçe'),
      'field_taslak_hukuki_dayanak' => $this->t('Hukukî dayanak'),
      'structure' => $this->t('Belge sırası ve üst başlık'),
    ];
    $make_value = static function (array $item): array {
      if (!isset($item['value']) || (string) $item['value'] === '') {
        return ['#plain_text' => 'Boş'];
      }
      if (!empty($item['format'])) {
        return ['#type' => 'processed_text', '#text' => (string) $item['value'], '#format' => (string) $item['format']];
      }
      return ['#plain_text' => (string) $item['value']];
    };
    $diff = [];
    $before_text = (string) ($before['value'] ?? '');
    $after_text = (string) ($after['value'] ?? '');
    if ($before_text !== $after_text) {
      $diff = ayb_mevzuat_calisma_draft_diff($before_text, $after_text);
      $diff['#title'] = $this->t('Kelime ve karakter farkları');
      $diff['#attributes']['class'][] = 'ayb-snapshot-character-diff';
      $diff['#open'] = TRUE;
    }
    return ['field' => $field, 'label' => $labels[$field] ?? $this->t('Diğer değişiklik'), 'before' => $make_value($before), 'after' => $make_value($after), 'diff' => $diff];
  }
}
