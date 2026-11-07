<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Selects and compares revisions of legislative working records. */
final class DraftRevisionComparisonForm extends FormBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /** {@inheritdoc} */
  public function getFormId(): string {
    return 'ayb_mevzuat_calisma_revision_comparison_form';
  }

  /** {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node || !in_array($node->bundle(), ['mevzuat_taslagi', 'mevzuat_taslak_maddesi', 'mevzuat_taslak_gorusu'], TRUE)) {
      return $form;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $revision_query = $storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('nid', $node->id())
      ->sort('vid', 'DESC');
    $revision_ids = array_keys($revision_query->execute());
    $revisions = [];
    $options = [];
    // Revision comparison can involve many saves. Load all revisions and
    // their authors in batches instead of issuing one storage query per row.
    $loaded_revisions = $revision_ids ? $storage->loadMultipleRevisions($revision_ids) : [];
    $author_ids = [];
    foreach ($loaded_revisions as $revision) {
      if ($revision && $revision->access('view revision')) {
        $author_ids[] = (int) $revision->getRevisionUserId();
      }
    }
    $authors = $author_ids ? $this->entityTypeManager->getStorage('user')->loadMultiple(array_unique($author_ids)) : [];
    foreach ($revision_ids as $revision_id) {
      $revision = $loaded_revisions[$revision_id] ?? NULL;
      if (!$revision || !$revision->access('view revision')) {
        continue;
      }
      $revisions[$revision_id] = $revision;
      $timestamp = $revision->getRevisionCreationTime();
      $date = $timestamp ? $this->dateFormatter->format($timestamp, 'short') : $this->t('Tarih yok');
      $author_user = $authors[$revision->getRevisionUserId()] ?? NULL;
      $author = $author_user?->getDisplayName() ?: $this->t('Bilinmeyen kullanıcı');
      $options[$revision_id] = $this->t('Revizyon @revision · @date · @author', [
        '@revision' => $revision_id,
        '@date' => $date,
        '@author' => $author,
      ]);
    }

    $form['#attributes']['class'][] = 'ayb-draft-revision-compare-form';
    $form['#prefix'] = '<div id="ayb-draft-revision-compare-wrapper">';
    $form['#suffix'] = '</div>';
    $record_label = match ($node->bundle()) {
      'mevzuat_taslak_maddesi' => $this->t('Madde çalışması'),
      'mevzuat_taslak_gorusu' => $this->t('Görüş kaydı'),
      default => $this->t('Mevzuat taslağı'),
    };
    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-revision-compare-intro']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('@type sürümlerini karşılaştır', ['@type' => $record_label])],
      'text' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('İki kaydedilmiş sürümü seçin. Değişen metin alanları ayrı ayrı, ekleme ve çıkarmalar satır içinde gösterilir.')],
    ];
    $form['node_context'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-revision-compare-context']],
      'label' => ['#plain_text' => $record_label],
      'title' => ['#plain_text' => $node->label()],
      'history' => [
        '#type' => 'link',
        '#title' => $this->t('Revizyon geçmişine dön'),
        '#url' => $node->toUrl('version-history'),
      ],
    ];

    if (count($options) < 2) {
      $form['not_enough_revisions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-revision-compare-unchanged']],
        'text' => ['#plain_text' => $this->t('Karşılaştırma için bu kaydın en az iki görüntülenebilir revizyonu olmalıdır.')],
      ];
      return $form;
    }

    $valid_ids = array_keys($options);
    $request = $this->getRequest();
    $stored_from = $form_state->get('compare_from');
    $stored_to = $form_state->get('compare_to');
    $input_from = $stored_from ?? $form_state->getValue('from_revision');
    $input_to = $stored_to ?? $form_state->getValue('to_revision');
    $requested_from = is_scalar($input_from) ? $input_from : $request->query->get('from');
    $requested_to = is_scalar($input_to) ? $input_to : $request->query->get('to');
    $default_to = $valid_ids[0];
    $default_from = $valid_ids[1];
    $from = is_scalar($requested_from) && isset($options[(string) $requested_from]) ? (string) $requested_from : (string) $default_from;
    $to = is_scalar($requested_to) && isset($options[(string) $requested_to]) ? (string) $requested_to : (string) $default_to;

    $field_labels = $this->getComparableFields($node);
    $field_values = [];
    $field_options = [];
    if (isset($revisions[$from], $revisions[$to])) {
      $old_revision = $revisions[$from];
      $new_revision = $revisions[$to];
      foreach ($field_labels as $field_name => $field_label) {
        if ($field_name === 'title') {
          $old_text = (string) $old_revision->label();
          $new_text = (string) $new_revision->label();
        }
        else {
          if (!$old_revision->hasField($field_name) || !$new_revision->hasField($field_name)) {
            continue;
          }
          $old_field = $old_revision->get($field_name);
          $new_field = $new_revision->get($field_name);
          if (!$old_field->access('view') || !$new_field->access('view')) {
            continue;
          }
          $old_text = (string) ($old_field->value ?? '');
          $new_text = (string) ($new_field->value ?? '');
        }
        if (trim(strip_tags($old_text)) === '' && trim(strip_tags($new_text)) === '') {
          continue;
        }
        $field_options[$field_name] = $field_label;
        $field_values[$field_name] = ['label' => $field_label, 'old' => $old_text, 'new' => $new_text];
      }
    }
    $primary_field = match ($node->bundle()) {
      'mevzuat_taslak_maddesi' => 'field_taslak_madde_oneri_metin',
      'mevzuat_taslak_gorusu' => 'field_taslak_gorus_metni',
      default => 'field_taslak_metin',
    };
    $default_field = isset($field_options[$primary_field]) ? $primary_field : array_key_first($field_options);
    $input_fields = $form_state->get('compare_fields');
    if ($input_fields === NULL) {
      $input_fields = $form_state->getValue('compare_fields');
    }
    $has_submitted_fields = $form_state->get('compare_fields_submitted') === TRUE;
    $selected_fields = $has_submitted_fields
      ? array_values(array_intersect(array_keys($field_options), array_filter((array) $input_fields)))
      : ($default_field ? [$default_field] : []);

    $form['from_revision'] = [
      '#type' => 'select',
      '#title' => $this->t('Önceki sürüm'),
      '#options' => $options,
      '#default_value' => $from,
      '#required' => TRUE,
    ];
    $form['compare_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Metin alanları'),
      '#options' => $field_options,
      '#default_value' => $selected_fields,
      '#attributes' => ['class' => ['ayb-draft-revision-compare-field-selector']],
      '#description' => $this->t('Karşılaştırmak istediğiniz alanları seçin. İlk açılışta ana metin alanı seçilidir.'),
    ];
    $form['to_revision'] = [
      '#type' => 'select',
      '#title' => $this->t('Yeni sürüm'),
      '#options' => $options,
      '#default_value' => $to,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['compare'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sürümleri karşılaştır'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::refreshComparison',
        'wrapper' => 'ayb-draft-revision-compare-wrapper',
      ],
    ];

    if (isset($revisions[$from], $revisions[$to])) {
      $old_revision = $revisions[$from];
      $new_revision = $revisions[$to];
      $from_label = $options[$from];
      $to_label = $options[$to];
      $form['comparison_summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-revision-compare-summary']],
        'from' => ['#plain_text' => $this->t('Önceki: @revision', ['@revision' => $from_label])],
        'to' => ['#plain_text' => $this->t('Yeni: @revision', ['@revision' => $to_label])],
      ];
      $old_log = trim((string) $old_revision->getRevisionLogMessage());
      $new_log = trim((string) $new_revision->getRevisionLogMessage());
      if ($old_log !== $new_log && ($old_log !== '' || $new_log !== '')) {
        $form['revision_notes'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-revision-compare-notes']],
          'old' => ['#plain_text' => $this->t('Önceki sürüm notu: @note', ['@note' => $old_log ?: $this->t('Not girilmemiş')])],
          'new' => ['#plain_text' => $this->t('Yeni sürüm notu: @note', ['@note' => $new_log ?: $this->t('Not girilmemiş')])],
        ];
      }
      $changed_fields = [];
      foreach ($selected_fields as $field_name) {
        if (isset($field_values[$field_name]) && $field_values[$field_name]['old'] !== $field_values[$field_name]['new']) {
          $changed_fields[$field_name] = $field_values[$field_name];
        }
      }
      if (!$selected_fields) {
        $form['comparison_result'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-revision-compare-unchanged']],
          'text' => ['#plain_text' => $this->t('Karşılaştırmak için en az bir metin alanı seçin.')],
        ];
      }
      elseif (!$changed_fields) {
        $form['comparison_result'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-revision-compare-unchanged']],
          'text' => ['#plain_text' => $this->t('Seçtiğiniz alanlarda bu iki sürüm arasında değişiklik yok.')],
        ];
      }
      else {
        $form['comparison_result'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-revision-compare-results']],
        ];
        foreach ($changed_fields as $field_name => $change) {
          $diff = ayb_mevzuat_calisma_draft_diff($change['old'], $change['new']);
          $diff['#title'] = $change['label'];
          $diff['#open'] = TRUE;
          $diff['#attributes']['class'][] = 'ayb-draft-revision-compare-diff';
          $form['comparison_result'][$field_name] = $diff;
        }
      }
    }

    $form['#attached']['library'][] = 'ayb_mevzuat_calisma/draft';
    $form['#cache']['contexts'][] = 'url.query_args:from';
    $form['#cache']['contexts'][] = 'url.query_args:to';
    $form['#cache']['contexts'][] = 'user.permissions';
    $form['#cache']['contexts'][] = 'user.node_grants:view';
    $form['#cache']['tags'][] = 'node:' . $node->id();
    return $form;
  }

  /** Returns the substantive text fields to compare for this record type. */
  private function getComparableFields(NodeInterface $node): array {
    $fields = match ($node->bundle()) {
      'mevzuat_taslagi' => [
        'title' => $this->t('Taslak başlığı'),
        'field_taslak_metin' => $this->t('Taslak metni'),
        'field_taslak_mevcut_metin' => $this->t('Mevcut mevzuat metni'),
        'field_taslak_nihai_metin' => $this->t('Nihai metin'),
        'field_taslak_genel_gerekce' => $this->t('Genel gerekçe'),
        'field_taslak_amac_kapsam' => $this->t('Amaç ve kapsam'),
        'field_taslak_hukuki_dayanak' => $this->t('Hukukî dayanak'),
        'field_taslak_fark_aciklama' => $this->t('Değişiklik açıklaması'),
        'field_taslak_karsilastirma_notu' => $this->t('Karşılaştırma notu'),
      ],
      'mevzuat_taslak_maddesi' => [
        'title' => $this->t('Madde çalışma başlığı'),
        'field_taslak_madde_mevcut_metin' => $this->t('Mevcut madde metni'),
        'field_taslak_madde_oneri_metin' => $this->t('Önerilen madde metni'),
        'field_taslak_madde_nihai_metin' => $this->t('Nihai madde metni'),
        'field_taslak_madde_gerekcesi' => $this->t('Madde gerekçesi'),
        'field_taslak_madde_karsilastirma' => $this->t('Madde karşılaştırma notu'),
      ],
      'mevzuat_taslak_gorusu' => [
        'title' => $this->t('Görüş kaydı başlığı'),
        'field_taslak_gorus_metni' => $this->t('Sunulan görüş'),
        'field_taslak_gorus_onerisi' => $this->t('Değişiklik önerisi'),
        'field_taslak_gorus_cevabi' => $this->t('İdarenin cevabı'),
      ],
      default => [],
    };
    return array_filter($fields, fn($label, $field_name): bool => $field_name === 'title' || $node->hasField($field_name), ARRAY_FILTER_USE_BOTH);
  }

  /** {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // The comparison dialog refreshes its contents through AJAX. Keep the
    // selected revisions in the form state instead of navigating away.
    if ($this->getRequest()->isXmlHttpRequest()) {
      $from = $form_state->getValue('from_revision');
      $to = $form_state->getValue('to_revision');
      $fields = $form_state->getValue('compare_fields');
      $form_state->set('compare_from', is_scalar($from) ? (string) $from : NULL);
      $form_state->set('compare_to', is_scalar($to) ? (string) $to : NULL);
      $form_state->set('compare_fields', is_array($fields) ? array_values(array_filter($fields)) : []);
      $form_state->set('compare_fields_submitted', TRUE);
      $form_state->setRebuild(TRUE);
      return;
    }
    $node = $this->getRouteMatch()->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return;
    }
    $form_state->setRedirect('ayb_mevzuat_calisma.revision_compare', ['node' => $node->id()], [
      'query' => [
        'from' => $form_state->getValue('from_revision'),
        'to' => $form_state->getValue('to_revision'),
      ],
    ]);
  }

  /** Re-renders the comparison inside its current dialog. */
  public function refreshComparison(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

}
