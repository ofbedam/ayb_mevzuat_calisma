<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/** Applies a review decision to selected opinion/suggestion records. */
final class DraftConsolidationBulkDecisionForm extends FormBase {

  private ?NodeInterface $draft = NULL;

  public function getFormId(): string {
    return 'ayb_mevzuat_calisma_consolidation_bulk_decision';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $args = $form_state->getBuildInfo()['args'] ?? [];
    $draft = $args[0] ?? \Drupal::routeMatch()->getParameter('draft');
    if (!$draft instanceof NodeInterface || $draft->bundle() !== 'mevzuat_taslagi') {
      return ['#markup' => $this->t('Geçerli bir taslak seçilmedi.')];
    }
    $this->draft = $draft;
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = array_merge(
      $storage->getQuery()->accessCheck(TRUE)->condition('type', 'mevzuat_taslak_gorusu')->condition('field_taslak_gorus_taslak', $draft->id())->sort('changed', 'DESC')->execute(),
      $storage->getQuery()->accessCheck(TRUE)->condition('type', 'mevzuat_taslak_onerisi')->condition('field_tslk_oneri_taslak', $draft->id())->sort('changed', 'DESC')->execute()
    );
    $records = $storage->loadMultiple(array_unique($ids));
    $options = [];
    foreach ($records as $record) {
      if (!$record instanceof NodeInterface || !$record->access('update')) {
        continue;
      }
      $type = $record->bundle() === 'mevzuat_taslak_gorusu' ? $this->t('Görüş') : $this->t('Madde önerisi');
      $options[$record->id()] = $type . ' — ' . $record->label();
    }
    $form['intro'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Birden fazla kayıt seçerek yalnızca karar durumunu toplu güncelleyebilirsiniz. Madde metnine uygulama için ilgili kaydın tekil karar ekranını kullanın.'), '#attributes' => ['class' => ['ayb-draft-bulk-decision__help']]];
    $form['records'] = ['#type' => 'checkboxes', '#title' => $this->t('İncelenecek kayıtlar'), '#options' => $options, '#empty_value' => []];
    $form['decision'] = ['#type' => 'select', '#title' => $this->t('Yeni karar durumu'), '#options' => ['incelemede' => $this->t('İnceleniyor'), 'kabul' => $this->t('Kabul edildi'), 'ret' => $this->t('Reddedildi')], '#required' => TRUE];
    $form['actions'] = ['#type' => 'actions', 'submit' => ['#type' => 'submit', '#value' => $this->t('Seçilen kararları güncelle'), '#button_type' => 'primary']];
    $form['#attributes']['class'][] = 'ayb-draft-bulk-decision';
    $form['#tree'] = TRUE;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter((array) $form_state->getValue(['records']));
    if (!$selected) {
      $this->messenger()->addWarning($this->t('En az bir görüş veya öneri seçin.'));
      return;
    }
    $decision = (string) $form_state->getValue(['decision']);
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $updated = 0;
    foreach (array_keys($selected) as $id) {
      $record = $storage->load((int) $id);
      if (!$record instanceof NodeInterface || !$record->access('update')) {
        continue;
      }
      $field = $record->bundle() === 'mevzuat_taslak_gorusu' ? 'field_taslak_gorus_sonucu' : 'field_tslk_oneri_durum';
      if (!$record->hasField($field)) {
        continue;
      }
      $value = $record->bundle() === 'mevzuat_taslak_gorusu'
        ? ['incelemede' => 'degerlendirilmedi', 'kabul' => 'kabul', 'ret' => 'reddedildi'][$decision]
        : ['incelemede' => 'inceleniyor', 'kabul' => 'kabul', 'ret' => 'ret'][$decision];
      $record->set($field, $value);
      $record->setNewRevision(TRUE);
      $record->setRevisionLogMessage($this->t('Konsolidasyon ekranından toplu karar durumu güncellendi.'));
      $record->save();
      $updated++;
    }
    $this->messenger()->addStatus($this->t('@count kayıt için karar durumu güncellendi.', ['@count' => $updated]));
  }

}
