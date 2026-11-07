<?php

namespace Drupal\ayb_mevzuat_calisma\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/** Opens a focused, AJAX-friendly edit form for a draft article. */
final class DraftInlineEditController extends ControllerBase {

  /** Builds the compact article form used by the draft page cards. */
  public function edit(NodeInterface $node): array {
    $request = \Drupal::request();
    $request->attributes->set('ayb_mevzuat_calisma_quick_edit', TRUE);
    try {
      $build = \Drupal::service('entity.form_builder')->getForm($node, 'default');
    }
    finally {
      $request->attributes->remove('ayb_mevzuat_calisma_quick_edit');
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-quick-edit-dialog']],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ayb-draft-quick-edit-dialog__intro']],
        '#value' => $this->t('Yalnızca bu maddenin metin ve gerekçe alanlarını hızlıca güncelliyorsunuz. Çalışma adı ve diğer yönetim alanları tam düzenleme sayfasında tutulur.'),
      ],
      'full_edit' => [
        '#type' => 'link',
        '#title' => $this->t('Tam düzenleme sayfasını aç'),
        '#url' => $node->toUrl('edit-form'),
        '#attributes' => ['class' => ['ayb-draft-quick-edit-dialog__full-edit']],
      ],
      'form' => $build,
    ];
  }

}
