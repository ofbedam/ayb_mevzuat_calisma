<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a drag-and-drop ordering form for draft article records.
 */
final class DraftArticleOrderingForm extends FormBase {

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }

  /** {@inheritdoc} */
  public function getFormId(): string {
    return 'ayb_mevzuat_calisma_draft_article_ordering_form';
  }

  /** {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface) {
      return ['message' => ['#plain_text' => $this->t('Taslak bulunamadı.')]];
    }

    $form['#draft_node'] = $node;
    $form['intro'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-ordering__intro']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Madde ve başlık sıralaması')],
      'text' => ['#plain_text' => $this->t('Kayıtları sürükleyerek önizleme, Word ve PDF çıktılarındaki sıralamayı belirleyin. Madde numaraları bu işlemle değiştirilmez.')],
    ];

    $ids = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_maddesi')
      ->condition('field_taslak_madde_taslak', $node->id())
      ->execute();
    $children = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids);
    usort($children, static function (NodeInterface $a, NodeInterface $b): int {
      $a_order = is_numeric($a->get('field_taslak_madde_sira')->value) ? (float) $a->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
      $b_order = is_numeric($b->get('field_taslak_madde_sira')->value) ? (float) $b->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
      return $a_order <=> $b_order ?: ((int) $a->id() <=> (int) $b->id());
    });

    $form['items'] = [
      '#type' => 'table',
      '#tree' => TRUE,
      '#header' => [$this->t('Kayıt'), $this->t('Üst bölüm / başlık'), $this->t('Tür'), $this->t('Madde numarası'), $this->t('Sıra')],
      '#empty' => $this->t('Bu taslağa bağlı madde veya başlık kaydı bulunmuyor.'),
      '#attributes' => ['class' => ['ayb-draft-ordering__table']],
      '#tabledrag' => [[
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'ayb-draft-ordering-weight',
      ]],
    ];
    foreach (array_values($children) as $index => $child) {
      $type = $child->get('field_taslak_kayit_turu')->value ?: 'madde';
      $allowed = $child->get('field_taslak_kayit_turu')->getFieldDefinition()->getSetting('allowed_values');
      $label = $child->label();
      $official_heading = $child->get('field_taslak_madde_basligi')->value;
      if ($official_heading) {
        $label .= ' · ' . $official_heading;
      }
      $form['items'][$child->id()] = [
        '#attributes' => ['class' => ['draggable']],
        'label' => [
          'data' => [
            '#type' => 'link',
            '#title' => $label,
            '#url' => $child->toUrl(),
          ],
        ],
        'parent' => ['#plain_text' => $child->hasField('field_taslak_ust_kayit') && !$child->get('field_taslak_ust_kayit')->isEmpty() && $child->get('field_taslak_ust_kayit')->entity ? $child->get('field_taslak_ust_kayit')->entity->label() : $this->t('Taslak kökü')],
        'type' => ['#plain_text' => $allowed[$type] ?? $type],
        'number' => ['#plain_text' => (string) $child->get('field_taslak_madde_numarasi')->value],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Sıra'),
          '#title_display' => 'invisible',
          '#default_value' => $index,
          '#attributes' => ['class' => ['ayb-draft-ordering-weight']],
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sıralamayı kaydet'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Taslağa dön'),
      '#url' => $node->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];
    $form['#attached']['library'][] = 'core/drupal.tabledrag';
    $form['#attached']['library'][] = 'ayb_mevzuat_calisma/draft';
    return $form;
  }

  /** {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $draft = $form['#draft_node'];
    $values = $form_state->getValue('items', []);
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $changed = 0;
    uasort($values, static fn(array $a, array $b): int => ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0)));
    foreach (array_keys($values) as $index => $id) {
      $child = $storage->load((int) $id);
      if (!$child instanceof NodeInterface || (int) $child->get('field_taslak_madde_taslak')->target_id !== (int) $draft->id() || !$child->access('update')) {
        continue;
      }
      $new_order = ($index + 1) * 10;
      if ((int) $child->get('field_taslak_madde_sira')->value !== $new_order) {
        $child->set('field_taslak_madde_sira', $new_order);
        $child->setNewRevision(TRUE);
        $child->setRevisionLogMessage($this->t('Madde sıralaması çalışma alanından güncellendi.'));
        $child->save();
        $changed++;
      }
    }
    $this->messenger()->addStatus($this->formatPlural($changed, '1 kayıt sıralandı.', '@count kayıt sıralandı.'));
    $form_state->setRedirect('ayb_mevzuat_calisma.draft_article_order', ['node' => $draft->id()]);
  }

}
