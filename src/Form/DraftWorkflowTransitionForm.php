<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\content_moderation\Form\EntityModerationForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/** Provides the focused moderation transition form inside the workspace. */
final class DraftWorkflowTransitionForm extends EntityModerationForm {

  /** {@inheritdoc} */
  public function getFormId() {
    return 'ayb_mevzuat_calisma_workflow_transition_form';
  }

  /** {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $draft = NULL, ?ContentEntityInterface $article = NULL) {
    $entity = $article ?: $draft;
    if (!$entity) {
      return ['message' => ['#plain_text' => $this->t('Çalışma kaydı bulunamadı.')]];
    }
    $form = parent::buildForm($form, $form_state, $entity);
    $form['#attributes']['class'][] = 'ayb-workspace-transition-form';
    if (isset($form['current'])) {
      $form['current']['#title'] = $this->t('Mevcut durum');
    }
    if (isset($form['new_state'])) {
      $form['new_state']['#title'] = $this->t('Yeni durum');
      $form['new_state']['#description'] = $this->t('Yalnızca bu kullanıcı için izin verilen geçişler listelenir.');
    }
    if (isset($form['revision_log'])) {
      $form['revision_log']['#title'] = $this->t('İşlem notu');
      $form['revision_log']['#description'] = $this->t('Bu geçişin gerekçesini kısa ve anlaşılır biçimde yazın.');
    }
    if (isset($form['submit'])) {
      $form['submit']['#value'] = $this->t('Durumu kaydet');
    }
    return $form;
  }

  /** {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $destination = $this->getRequest()->query->get('destination');
    if (is_string($destination) && $destination !== '' && Url::fromUserInput($destination)->isExternal() === FALSE) {
      $form_state->setRedirectUrl(Url::fromUserInput($destination));
    }
  }

}
