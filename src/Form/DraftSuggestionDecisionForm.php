<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/** Confirms acceptance or rejection of a madde suggestion. */
final class DraftSuggestionDecisionForm extends ConfirmFormBase {

  private ?NodeInterface $suggestion = NULL;

  private string $decision = 'reject';

  public function getFormId(): string {
    return 'ayb_mevzuat_calisma_draft_suggestion_decision';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $suggestion = NULL, ?string $decision = NULL): array {
    $this->suggestion = $suggestion;
    $this->decision = $decision === 'accept' ? 'accept' : 'reject';
    if (!$this->suggestion || $this->suggestion->bundle() !== 'mevzuat_taslak_onerisi') {
      $form['error'] = ['#markup' => $this->t('Geçerli bir madde önerisi seçilmedi.')];
      return $form;
    }
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-suggestion-decision']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->suggestion->label()],
      'message' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->decision === 'accept'
        ? $this->t('Eski ifade hedef alanda tam olarak bir kez bulunursa yalnızca bu ifade değiştirilecektir. Metin değişmişse işlem durdurulur.')
        : $this->t('Öneri reddedilecek; mevcut metin değiştirilmeyecektir.')],
    ];
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return $this->decision === 'accept'
      ? (string) $this->t('Bu öneri kabul edilip metne uygulansın mı?')
      : (string) $this->t('Bu öneri reddedilsin mi?');
  }

  public function getCancelUrl(): Url {
    $article = $this->article();
    return $article ? $article->toUrl() : Url::fromRoute('ayb_mevzuat_calisma.draft_workspace');
  }

  public function getConfirmText(): string {
    return $this->decision === 'accept' ? (string) $this->t('Kabul et ve uygula') : (string) $this->t('Öneriyi reddet');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $suggestion = $this->suggestion;
    $article = $this->article();
    if (!$suggestion || !$article || !$article->access('update')) {
      $this->messenger()->addError($this->t('Öneri veya ilgili madde üzerinde işlem yapma yetkiniz yok.'));
      return;
    }
    if (\Drupal::service('ayb_mevzuat_calisma.lock')->isLockedByOther($article)) {
      $this->messenger()->addError($this->t('Madde başka bir kullanıcı tarafından düzenleniyor. İşlem tamamlandıktan sonra öneriyi yeniden değerlendirin.'));
      return;
    }
    if ($this->decision === 'accept') {
      $area = (string) $suggestion->get('field_tslk_oneri_alan')->value;
      $target = ['oneri' => 'field_taslak_madde_oneri_metin', 'nihai' => 'field_taslak_madde_nihai_metin', 'gerekce' => 'field_taslak_madde_gerekcesi'][$area] ?? NULL;
      if (!$target || !$article->hasField($target)) {
        $this->messenger()->addError($this->t('Önerinin hedef metin alanı bu maddede bulunmuyor.'));
        return;
      }
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $storage->resetCache([$article->id(), $suggestion->id()]);
      $article = $storage->load($article->id());
      $suggestion = $storage->load($suggestion->id());
      if (!$article || !$suggestion || !$article->access('update') || !$suggestion->access('update')) {
        $this->messenger()->addError($this->t('Kayıt güncellenemiyor veya yetkiniz bulunmuyor.'));
        return;
      }
      if ((string) $suggestion->get('field_tslk_oneri_durum')->value !== 'acik') {
        $this->messenger()->addError($this->t('Bu öneri daha önce değerlendirilmiş.'));
        return;
      }
      $old = (string) $suggestion->get('field_tslk_oneri_eski')->value;
      $new = (string) $suggestion->get('field_tslk_oneri_yeni')->value;
      $current = (string) $article->get($target)->value;
      if ($old === '' || substr_count($current, $old) !== 1) {
        $this->messenger()->addError($this->t('Önerinin eski ifadesi güncel metinde tam olarak bir kez bulunamadı. Metni ve öneriyi karşılaştırıp yeniden değerlendirin; hiçbir değişiklik yapılmadı.'));
        return;
      }
      $format = $article->get($target)->format ?: 'basic_html';
      $article->setNewRevision(TRUE);
      $article->setRevisionLogMessage($this->t('Madde önerisi kabul edilerek metne uygulandı: @title', ['@title' => $suggestion->label()]));
      $article->set($target, ['value' => str_replace($old, $new, $current), 'format' => $format]);
      $article->save();
      $suggestion->set('field_tslk_oneri_durum', 'kabul');
    }
    else {
      $suggestion->set('field_tslk_oneri_durum', 'ret');
    }
    $suggestion->setNewRevision(TRUE);
    $suggestion->setRevisionLogMessage($this->decision === 'accept' ? 'Öneri kabul edildi ve uygulandı.' : 'Öneri reddedildi.');
    $suggestion->save();
    $this->messenger()->addStatus($this->decision === 'accept' ? $this->t('Öneri kabul edildi ve maddeye uygulandı.') : $this->t('Öneri reddedildi.'));
    $form_state->setRedirect('entity.node.canonical', ['node' => $article->id()]);
  }

  private function article(): ?NodeInterface {
    if (!$this->suggestion || !$this->suggestion->hasField('field_tslk_oneri_madde')) {
      return NULL;
    }
    $article = $this->suggestion->get('field_tslk_oneri_madde')->entity;
    return $article instanceof NodeInterface && $article->bundle() === 'mevzuat_taslak_maddesi' ? $article : NULL;
  }
}
