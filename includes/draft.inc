<?php

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/** Implements hook_entity_type_alter(). */
function ayb_mevzuat_calisma_entity_type_alter(array &$entity_types): void {
  $entity_types['node']->addConstraint('AybDraftConsistency');
}

/** Implements hook_node_access(). */
function ayb_mevzuat_calisma_node_access(NodeInterface $node, $op, AccountInterface $account): AccessResult {
  if ($op === 'delete' && in_array($node->bundle(), ['mevzuat_taslagi', 'mevzuat_taslak_maddesi'], TRUE)) {
    $relations = $node->bundle() === 'mevzuat_taslagi'
      ? ['mevzuat_taslak_maddesi' => 'field_taslak_madde_taslak', 'mevzuat_taslak_gorusu' => 'field_taslak_gorus_taslak', 'mevzuat_taslak_onerisi' => 'field_tslk_oneri_taslak']
      : ['mevzuat_taslak_maddesi' => 'field_taslak_ust_kayit', 'mevzuat_taslak_gorusu' => 'field_taslak_gorus_madde', 'mevzuat_taslak_onerisi' => 'field_tslk_oneri_madde'];
    foreach ($relations as $bundle => $field) {
      $query = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', $bundle)->condition($field, $node->id())->range(0, 1);
      if ($query->execute()) {
        return AccessResult::forbidden('Bu kayda bağlı madde, görüş veya öneri bulunduğundan silinemez; önce ilişkili kayıtları güvenli biçimde taşıyın veya kaldırın.')
          ->addCacheableDependency($node)
          ->addCacheTags(['node_list'])
          ->cachePerUser();
      }
    }
    return AccessResult::neutral()->addCacheableDependency($node)->cachePerUser();
  }
  $parent_field = match ($node->bundle()) {
    'mevzuat_taslak_maddesi' => 'field_taslak_madde_taslak',
    'mevzuat_taslak_gorusu' => 'field_taslak_gorus_taslak',
    default => NULL,
  };
  if (!$parent_field || !in_array($op, ['view', 'update'], TRUE)) {
    return AccessResult::neutral();
  }
  $parent = $node->hasField($parent_field) ? $node->get($parent_field)->entity : NULL;
  if (!$parent instanceof NodeInterface) {
    return AccessResult::forbidden('Kayıt bir üst mevzuat taslağına bağlı olmalıdır.')
      ->cachePerUser();
  }
  // Public visitors may read only published article records under a
  // published, approved draft. Opinions and suggestions remain private.
  if ($op === 'view' && $node->bundle() === 'mevzuat_taslak_maddesi'
    && $node->isPublished()
    && $parent->isPublished()
    && $parent->hasField('moderation_state')
    && in_array((string) $parent->get('moderation_state')->value, ['onaylandi', 'published'], TRUE)) {
    return AccessResult::allowed()
      ->addCacheableDependency($parent)
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }
  $parent_access = $parent->access($op === 'update' ? 'update' : 'view', $account, TRUE);
  if (!$parent_access->isAllowed()) {
    return AccessResult::forbidden('Üst mevzuat taslağına erişim yetkisi bulunmuyor.')
      ->addCacheableDependency($parent)
      ->addCacheableDependency($parent_access)
      ->cachePerUser();
  }
  if ($node->bundle() !== 'mevzuat_taslak_gorusu' || $op !== 'view') {
    return AccessResult::neutral()
      ->addCacheableDependency($parent)
      ->addCacheableDependency($parent_access)
      ->cachePerUser();
  }
  // A publication choice alone must never expose confidential submissions.
  return AccessResult::allowedIfHasPermission($account, 'administer ayb legal information')
    ->orIf(AccessResult::allowedIfHasPermission($account, 'access ayb drafting workspace'))
    ->orIf(AccessResult::allowedIfHasPermission($account, 'administer nodes'))
    ->isAllowed()
      ? AccessResult::allowed()->addCacheableDependency($parent)->cachePerPermissions()
      : AccessResult::forbidden('Görüşler hukukî bilgi yönetimi yetkisi gerektirir.')
        ->addCacheableDependency($parent)
        ->cachePerPermissions();
}

/** Implements hook_node_view(). */
function ayb_mevzuat_calisma_node_view(array &$build, NodeInterface $node, EntityViewDisplayInterface $display, $view_mode): void {
  $bundle = $node->bundle();
  if (!in_array($bundle, ['mevzuat_taslagi', 'mevzuat_taslak_maddesi', 'mevzuat_taslak_gorusu', 'mevzuat_taslak_onerisi'], TRUE) || $view_mode !== 'full') {
    return;
  }
  $build['#attached']['library'][] = 'ayb_mevzuat_calisma/draft';
  $build['#attributes']['class'][] = 'ayb-draft-page';
  $draft = NULL;
  $article = NULL;
  if ($bundle === 'mevzuat_taslagi') {
    $draft = $node;
  }
  elseif ($bundle === 'mevzuat_taslak_maddesi' && $node->hasField('field_taslak_madde_taslak')) {
    $draft = $node->get('field_taslak_madde_taslak')->entity;
    $article = $node;
  }
  elseif ($bundle === 'mevzuat_taslak_gorusu' && $node->hasField('field_taslak_gorus_taslak')) {
    $draft = $node->get('field_taslak_gorus_taslak')->entity;
  }
  elseif ($bundle === 'mevzuat_taslak_onerisi' && $node->hasField('field_tslk_oneri_taslak')) {
    $draft = $node->get('field_tslk_oneri_taslak')->entity;
    $article = $node->hasField('field_tslk_oneri_madde') ? $node->get('field_tslk_oneri_madde')->entity : NULL;
  }
  if ($draft instanceof NodeInterface) {
    $build['ayb_draft_context_navigation'] = \Drupal::service('ayb_mevzuat_calisma.context_navigation')->build($draft, 'workspace', $article, FALSE, $node) + ['#weight' => -30];
  }
  $build['ayb_draft_workspace_toolbar'] = ayb_mevzuat_calisma_workspace_toolbar($node);
  $build['ayb_draft_section_nav'] = ayb_mevzuat_calisma_draft_section_nav($node);
  $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
  $approved_view = $bundle === 'mevzuat_taslagi' && in_array($state, ['onaylandi', 'published'], TRUE);
  $article_model = $bundle === 'mevzuat_taslagi'
    && $node->hasField('field_taslak_calisma_modeli')
    && $node->get('field_taslak_calisma_modeli')->value === 'madde_bazli';
  if ($approved_view) {
    $final_text_ready = $node->hasField('field_taslak_nihai_metin') && !$node->get('field_taslak_nihai_metin')->isEmpty();
    $build['ayb_draft_approved_header'] = [
      '#type' => 'container', '#weight' => -18,
      '#attributes' => ['class' => ['ayb-draft-approved-header']],
      'eyebrow' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-approved-header__eyebrow']], '#value' => t('Onaylı mevzuat taslağı')],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $final_text_ready ? t('Nihai metin') : t('Nihai metin önizlemesi')],
      'description' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $final_text_ready
        ? t('Bu kayıt onaylanmış sürümü gösterir. Metin, karar ve dayanak bilgileri aşağıda birlikte sunulmuştur.')
        : t('Kayıt onaylanmış durumda; ancak nihai metin alanı henüz doldurulmamış. Aşağıda taslak metin önizleme olarak gösterilmektedir.')],
      'notice' => (!$final_text_ready && $node->access('update')) ? ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-approved-header__notice'], 'role' => 'status'], '#value' => t('Nihai metin alanı boş. Yayına alma öncesinde nihai metni ve karar bilgilerini tamamlayın.')] : [],
    ];
  }
  if ($bundle !== 'mevzuat_taslagi') {
    $parent_field = match ($bundle) {
      'mevzuat_taslak_maddesi' => 'field_taslak_madde_taslak',
      'mevzuat_taslak_onerisi' => 'field_tslk_oneri_taslak',
      default => 'field_taslak_gorus_taslak',
    };
    $parent = $node->get($parent_field)->entity;
    if ($parent && $parent->access('view')) {
      $build['ayb_draft_child_context'] = [
        '#type' => 'container', '#weight' => -10,
        '#attributes' => ['class' => ['ayb-draft-child-context']],
        'label' => ['#plain_text' => t('Üst mevzuat taslağı')],
        'parent' => $parent->toLink()->toRenderable(),
      ];
      if ($bundle === 'mevzuat_taslak_maddesi') {
        $create_access = \Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess('mevzuat_taslak_gorusu', NULL, [], TRUE);
        CacheableMetadata::createFromObject($create_access)->applyTo($build['ayb_draft_child_context']);
        if ($create_access->isAllowed() && $parent->access('update')) {
          $build['ayb_draft_child_context']['add_opinion'] = [
            '#type' => 'link', '#title' => t('Bu madde hakkında görüş kaydı ekle'),
            '#url' => \Drupal\Core\Url::fromRoute('node.add', ['node_type' => 'mevzuat_taslak_gorusu'], ['query' => ['taslak' => $parent->id(), 'madde' => $node->id(), 'destination' => \Drupal::request()->getRequestUri()]]),
            '#attributes' => ['class' => ['button', 'ayb-draft-add']],
          ];
          if (\Drupal::currentUser()->hasPermission('create mevzuat_taslak_onerisi content') && $parent->access('update')) {
            $build['ayb_draft_child_context']['add_suggestion'] = [
              '#type' => 'link', '#title' => t('Bu madde için öneri ekle'),
              '#url' => \Drupal\Core\Url::fromRoute('node.add', ['node_type' => 'mevzuat_taslak_onerisi'], ['query' => ['taslak' => $parent->id(), 'madde' => $node->id(), 'destination' => \Drupal::request()->getRequestUri()]]),
              '#attributes' => ['class' => ['button', 'ayb-draft-add', 'ayb-draft-add--suggestion']],
            ];
          }
        }
      }
      elseif ($bundle === 'mevzuat_taslak_gorusu' && !$node->get('field_taslak_gorus_madde')->isEmpty()) {
        $article = $node->get('field_taslak_gorus_madde')->entity;
        if ($article && $article->access('view')) {
          $build['ayb_draft_child_context']['article_label'] = ['#plain_text' => t('İlgili taslak maddesi')];
          $build['ayb_draft_child_context']['article'] = $article->toLink()->toRenderable();
        }
      }
      elseif ($bundle === 'mevzuat_taslak_onerisi' && $node->hasField('field_tslk_oneri_madde') && !$node->get('field_tslk_oneri_madde')->isEmpty()) {
        $article = $node->get('field_tslk_oneri_madde')->entity;
        if ($article && $article->access('view')) {
          $build['ayb_draft_child_context']['article_label'] = ['#plain_text' => t('Önerinin ilgili olduğu madde')];
          $build['ayb_draft_child_context']['article'] = $article->toLink()->toRenderable();
        }
      }
      CacheableMetadata::createFromObject($parent)->applyTo($build['ayb_draft_child_context']);
    }
  }
  if ($bundle === 'mevzuat_taslak_maddesi') {
    $build['ayb_draft_suggestions'] = ayb_mevzuat_calisma_draft_suggestions($node);
  }
  // Keep the operational guide available on the public/view screen as well
  // as on the edit form.  It is intentionally collapsed so it does not
  // compete with the draft content.
  if (!$approved_view && $bundle === 'mevzuat_taslagi' && $article_model) {
    $build['ayb_draft_madde_view_guide'] = ayb_mevzuat_calisma_madde_form_guide(FALSE, FALSE);
    $build['ayb_draft_madde_view_guide']['#weight'] = -8;
    $build['ayb_draft_madde_view_guide']['#attributes']['class'][] = 'ayb-draft-form-guide--view';
  }
  elseif (!$approved_view && $bundle === 'mevzuat_taslak_maddesi') {
    $build['ayb_draft_madde_view_guide'] = ayb_mevzuat_calisma_madde_form_guide(TRUE, FALSE);
    $build['ayb_draft_madde_view_guide']['#weight'] = -8;
    $build['ayb_draft_madde_view_guide']['#attributes']['class'][] = 'ayb-draft-form-guide--view';
  }
  $groups = $approved_view ? [
    'kunye' => ['Künye', ['field_taslak_turu', 'field_taslak_dosya_no', 'field_taslak_versiyon', 'field_taslak_hazirlayan', 'field_taslak_sorumlu_birim', 'field_taslak_tarihi', 'field_taslak_durumu', 'field_taslak_mevzuat', 'field_taslak_sozlesmeler']],
    'metin' => ['Onaylı metin', [$node->hasField('field_taslak_nihai_metin') && !$node->get('field_taslak_nihai_metin')->isEmpty() ? 'field_taslak_nihai_metin' : 'field_taslak_metin']],
    'gerekce' => ['Gerekçe ve hukukî dayanak', ['field_taslak_nihai_gerekce', 'field_taslak_genel_gerekce', 'field_taslak_hukuki_dayanak', 'field_taslak_amac_kapsam']],
    'karar' => ['Onay ve karar', ['field_taslak_onay_notu', 'field_taslak_sonuc', 'field_taslak_nihai_mevzuat']],
    'belge' => ['İlgili belgeler', ['field_taslak_belge_media', 'field_taslak_cevap_raporu_media', 'field_taslak_gorus_raporu_media']],
  ] : ($bundle === 'mevzuat_taslagi' ? [
    'kunye' => ['Çalışma künyesi', ['field_taslak_calisma_modeli', 'field_taslak_baslik_hizalama', 'field_taslak_numara_gosterim', 'field_taslak_senaryosu', 'field_taslak_turu', 'field_taslak_dosya_no', 'field_taslak_versiyon', 'field_taslak_hazirlayan', 'field_taslak_sorumlu_birim', 'field_taslak_tarihi', 'field_taslak_durumu', 'field_taslak_mevzuat', 'field_taslak_sozlesmeler', 'field_taslak_onceki', 'field_taslak_nihai_mevzuat', 'field_taslak_maddeler']],
    'metin' => ['Taslak metni', ['field_taslak_metin']],
    'gerekce' => ['Genel gerekçe ve düzenleme çerçevesi', ['field_taslak_amac_kapsam', 'field_taslak_genel_gerekce', 'field_taslak_hukuki_dayanak', 'field_taslak_politika_notu', 'field_taslak_etki_degerlendirme', 'field_taslak_uygulama_plani']],
    'karsilastirma' => ['Mevcut metin ve değişiklik açıklamaları', ['field_taslak_mevcut_metin', 'field_taslak_fark_aciklama', 'field_taslak_karsilastirma_notu']],
    'gorus' => ['Görüş süreci', ['field_taslak_gorus_yontemi', 'field_taslak_gorus_durum', 'field_taslak_gorus_baslangic', 'field_taslak_gorus_bitis', 'field_taslak_gorus_cagrisi', 'field_taslak_gorus_raporu_media', 'field_taslak_cevap_raporu_media']],
    'nihai' => ['Nihai metin ve karar', ['field_taslak_nihai_metin', 'field_taslak_nihai_gerekce', 'field_taslak_onay_notu', 'field_taslak_sonuc']],
    'belge' => ['Çalışma belgeleri', ['field_taslak_belge_media']],
  ] : ($bundle === 'mevzuat_taslak_maddesi' ? [
    'kunye' => ['Madde künyesi', ['field_taslak_madde_taslak', 'field_taslak_kayit_turu', 'field_taslak_bolum_basligi', 'field_taslak_madde_numarasi', 'field_taslak_madde_basligi', 'field_taslak_madde_mevcut', 'field_taslak_madde_deg_turu', 'field_taslak_madde_durum']],
    'metin' => ['Önerilen madde ve gerekçe', ['field_taslak_madde_oneri_metin', 'field_taslak_madde_gerekcesi']],
    'karsilastirma' => ['Mevcut ve nihai metin', ['field_taslak_madde_mevcut_metin', 'field_taslak_madde_nihai_metin', 'field_taslak_madde_karsilastirma']],
    'belge' => ['Madde belgeleri', ['field_taslak_madde_belge_media']],
  ] : [
    'kunye' => ['Görüş künyesi', ['field_taslak_gorus_taslak', 'field_taslak_gorus_sunan_turu', 'field_taslak_gorus_sunan', 'field_taslak_gorus_tarih', 'field_taslak_gorus_yontem', 'field_taslak_gorus_yayin_durumu']],
    'metin' => ['Görüş ve öneri', ['field_taslak_gorus_metni', 'field_taslak_gorus_onerisi']],
    'nihai' => ['İdarenin değerlendirmesi', ['field_taslak_gorus_sonucu', 'field_taslak_gorus_cevabi']],
    'belge' => ['Görüş belgeleri', ['field_taslak_gorus_belge_media']],
  ]));
  if ($article_model && !$approved_view) {
    $groups['metin'][0] = 'Madde bazlı çalışma';
    $groups['metin'][1] = array_values(array_diff($groups['metin'][1], ['field_taslak_metin']));
    $groups['karsilastirma'][1] = array_values(array_diff($groups['karsilastirma'][1], ['field_taslak_mevcut_metin']));
    $groups['nihai'][1] = array_values(array_diff($groups['nihai'][1], ['field_taslak_nihai_metin']));
    unset($build['field_taslak_metin'], $build['field_taslak_mevcut_metin'], $build['field_taslak_nihai_metin']);
  }
  foreach ($groups as $id => [$title, $fields]) {
    $items = [];
    foreach ($fields as $field) {
      if (isset($build[$field]) && $node->hasField($field) && !$node->get($field)->isEmpty()) {
        $items[$field] = $build[$field];
        unset($build[$field]);
      }
    }
    if ($items) {
      if ($approved_view && $id === 'metin') {
        foreach ($items as &$item) {
          if (isset($item['#title'])) {
            $item['#title'] = t('Metin');
          }
        }
        unset($item);
      }
      $build['ayb_draft_' . $id] = [
        '#type' => 'details', '#title' => t($title),
        '#open' => $approved_view ? $id === 'metin' : in_array($id, ['kunye', 'metin'], TRUE),
        '#attributes' => ['id' => 'ayb-draft-section-' . $id, 'class' => ['ayb-draft-section', 'ayb-draft-section--' . $id]],
        '#weight' => array_search($id, array_keys($groups), TRUE),
      ] + $items;
    }
  }
  if ($approved_view) {
    foreach (['field_taslak_calisma_modeli', 'field_taslak_baslik_hizalama', 'field_taslak_numara_gosterim', 'field_taslak_senaryosu', 'field_taslak_mevcut_metin', 'field_taslak_fark_aciklama', 'field_taslak_karsilastirma_notu', 'field_taslak_gorus_yontemi', 'field_taslak_gorus_durum', 'field_taslak_gorus_baslangic', 'field_taslak_gorus_bitis', 'field_taslak_gorus_cagrisi', 'field_taslak_maddeler', 'field_taslak_onceki', 'field_taslak_politika_notu', 'field_taslak_etki_degerlendirme', 'field_taslak_uygulama_plani'] as $hidden_field) {
      unset($build[$hidden_field]);
    }
  }
  if ($bundle === 'mevzuat_taslagi') {
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($node);
    if ($workflow && $workflow->getTypePlugin()->hasState($state)) {
      $stage_help = match ($state) {
        'draft' => t('Taslağın metin ve gerekçesini tamamlayın. Madde bazlı çalışmak için aşağıdaki Madde Çalışmaları bölümünden kayıt ekleyebilirsiniz.'),
        'incelemede' => t('Taslak hukukî incelemede. İnceleme notlarını ve gerekli düzeltmeleri kaydedin.'),
        'goruste' => t('Görüş süreci açık. Gelen görüşleri aşağıdaki Görüşler ve Değerlendirmeler bölümünden takip edin.'),
        'revize' => t('Görüş ve inceleme sonuçlarına göre taslak metni veya madde çalışmalarını güncelleyin.'),
        'onaylandi' => t('Taslak onaylandı. Yayımlama öncesinde nihai metin ve karar bilgilerini gözden geçirin.'),
        'published' => t('Taslak yayımlandı. Bu kayıt yayımlanan son sürümü gösterir.'),
        'arsiv' => t('Bu çalışma arşivlenmiştir.'),
        default => t('Taslağın güncel çalışma aşaması aşağıda gösterilmektedir.'),
      };
      $build['ayb_draft_stage'] = [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-draft-stage']], '#weight' => -10,
        'label' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-stage__label']], 'text' => ['#plain_text' => t('Güncel aşama: @state', ['@state' => $workflow->getTypePlugin()->getState($state)->label()])]],
        'help' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-stage__help']], 'text' => ['#plain_text' => $stage_help]],
      ];
      CacheableMetadata::createFromRenderArray($build)->merge(CacheableMetadata::createFromObject($workflow))->applyTo($build);
    }
    if (!$approved_view) {
      $build['ayb_draft_usage_guide'] = [
      '#type' => 'container', '#weight' => -9,
      '#attributes' => ['class' => ['ayb-draft-usage-guide']],
      'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => t('Bu taslak nasıl kullanılır?')],
      'steps' => [
        '#theme' => 'item_list',
        '#items' => [
          t('Önce taslak senaryosunu ve künyeyi tamamlayın. Mevcut mevzuatı değiştiriyorsanız ilgili mevzuat kaydını seçin.'),
          t('Metnin tamamı hazırsa “Taslak metni” bölümüne tek parça olarak girin. Madde bazında değişiklik, yeniden numaralandırma veya ayrı gerekçe takibi gerekiyorsa her maddeyi “Madde çalışmaları” bölümünden ayrı ekleyin; aynı metni iki yerde tekrarlamayın.'),
          t('Görüş kayıtlarını yalnızca görüş süreci açıldığında, her kurum veya kişi için ayrı oluşturun. Maddeye ilişkin görüşlerde ilgili maddeyi de seçin.'),
          t('İnceleme ve onay aşamasında nihai metni, gerekçeyi ve karar bilgisini tamamlayın.'),
        ],
      ],
      ];
    }
    if (!$approved_view) {
      $build['ayb_draft_article_text'] = ayb_mevzuat_calisma_draft_article_text($node);
    }
    unset($build['field_taslak_gorus_sayisi']);
    if (!$approved_view && $node->access('update')) {
      $build['ayb_draft_relations'] = ayb_mevzuat_calisma_draft_relations($node);
    }
  }
  $pair = $bundle === 'mevzuat_taslagi'
    ? ['field_taslak_mevcut_metin', 'field_taslak_metin']
    : ($bundle === 'mevzuat_taslak_maddesi' ? ['field_taslak_madde_mevcut_metin', 'field_taslak_madde_oneri_metin'] : NULL);
  if (!$approved_view && $pair && !$node->get($pair[0])->isEmpty() && !$node->get($pair[1])->isEmpty()
    && $node->get($pair[0])->access('view') && $node->get($pair[1])->access('view')) {
    $build['ayb_draft_diff'] = ayb_mevzuat_calisma_draft_comparison($node, $pair[0], $pair[1], 'Mevcut metin', 'Taslak metni');
  }
}

/** Builds compact in-page navigation for sections that contain data. */
function ayb_mevzuat_calisma_draft_section_nav(NodeInterface $node): array {
  $sections = [
    'kunye' => [t('Çalışma künyesi'), ['field_taslak_calisma_modeli', 'field_taslak_baslik_hizalama', 'field_taslak_numara_gosterim', 'field_taslak_senaryosu', 'field_taslak_turu', 'field_taslak_dosya_no', 'field_taslak_versiyon', 'field_taslak_hazirlayan', 'field_taslak_sorumlu_birim', 'field_taslak_tarihi', 'field_taslak_durumu', 'field_taslak_mevzuat', 'field_taslak_sozlesmeler']],
    'metin' => [t('Taslak metni'), ['field_taslak_metin']],
    'gerekce' => [t('Gerekçe ve çerçeve'), ['field_taslak_amac_kapsam', 'field_taslak_genel_gerekce', 'field_taslak_hukuki_dayanak', 'field_taslak_politika_notu', 'field_taslak_etki_degerlendirme', 'field_taslak_uygulama_plani']],
    'karsilastirma' => [t('Karşılaştırma'), ['field_taslak_mevcut_metin', 'field_taslak_fark_aciklama', 'field_taslak_karsilastirma_notu']],
    'gorus' => [t('Görüş süreci'), ['field_taslak_gorus_yontemi', 'field_taslak_gorus_durum', 'field_taslak_gorus_baslangic', 'field_taslak_gorus_bitis', 'field_taslak_gorus_cagrisi', 'field_taslak_gorus_raporu_media', 'field_taslak_cevap_raporu_media']],
    'nihai' => [t('Nihai metin ve karar'), ['field_taslak_nihai_metin', 'field_taslak_nihai_gerekce', 'field_taslak_onay_notu', 'field_taslak_sonuc']],
    'karar' => [t('Onay ve karar'), ['field_taslak_onay_notu', 'field_taslak_sonuc', 'field_taslak_nihai_mevzuat']],
    'belge' => [t('Belgeler'), ['field_taslak_belge_media']],
  ];
  $items = [];
  foreach ($sections as $id => [$label, $fields]) {
    $visible = FALSE;
    foreach ($fields as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $visible = TRUE;
        break;
      }
    }
    if (!$visible) {
      continue;
    }
    $items[$id] = [
      '#type' => 'link',
      '#title' => $label,
      '#url' => \Drupal\Core\Url::fromUri('internal:#ayb-draft-section-' . $id),
    ];
  }
  $approved_view = $node->bundle() === 'mevzuat_taslagi'
    && $node->hasField('moderation_state')
    && in_array((string) $node->get('moderation_state')->value, ['onaylandi', 'published'], TRUE);
  if ($node->bundle() === 'mevzuat_taslagi' && !$approved_view) {
    $items['article_text'] = [
      '#type' => 'link', '#title' => t('Madde bazlı metin'),
      '#url' => \Drupal\Core\Url::fromUri('internal:#ayb-draft-article-text'),
    ];
    $items['relations'] = [
      '#type' => 'link', '#title' => t('Madde ve görüş kayıtları'),
      '#url' => \Drupal\Core\Url::fromUri('internal:#ayb-draft-relations'),
    ];
  }
  return [
    '#theme' => 'ayb_mevzuat_calisma_section_nav',
    '#weight' => -19,
    '#label' => t('Bölüme git'),
    '#items' => ['#theme' => 'item_list', '#items' => $items],
  ];
}

/** Builds a permission-aware workspace header shared by view pages. */
function ayb_mevzuat_calisma_workspace_toolbar(NodeInterface $node): array {
  $account = \Drupal::currentUser();
  $bundle_labels = [
    'mevzuat_taslagi' => t('Mevzuat taslağı'),
    'mevzuat_taslak_maddesi' => t('Madde çalışması'),
    'mevzuat_taslak_gorusu' => t('Görüş kaydı'),
    'mevzuat_taslak_onerisi' => t('Madde önerisi'),
  ];
  $toolbar = [
    '#type' => 'container',
    '#weight' => -20,
    '#attributes' => ['class' => ['ayb-draft-workspace-toolbar'], 'aria-label' => t('Taslak çalışma araçları')],
    '#attached' => ['library' => ['ayb_mevzuat_calisma/draft']],
    'identity' => [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-workspace-toolbar__identity']],
      'kind' => ['#plain_text' => $bundle_labels[$node->bundle()] ?? t('Mevzuat çalışma kaydı')],
    ],
    'metadata' => ['#type' => 'html_tag', '#tag' => 'ul', '#attributes' => ['class' => ['ayb-draft-workspace-toolbar__metadata']]],
    'actions' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-workspace-toolbar__actions']], '#weight' => 10],
    '#cache' => ['contexts' => ['user', 'user.permissions', 'user.node_grants:view']],
  ];
  $workspace_url = NULL;
  if ($node->bundle() === 'mevzuat_taslagi') {
    $workspace_url = \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $node->id()]);
  }
  elseif ($node->bundle() === 'mevzuat_taslak_maddesi' && $node->hasField('field_taslak_madde_taslak') && !$node->get('field_taslak_madde_taslak')->isEmpty()) {
    $workspace_url = \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.workspace_article', ['draft' => $node->get('field_taslak_madde_taslak')->target_id, 'article' => $node->id()]);
  }
  elseif ($node->bundle() === 'mevzuat_taslak_onerisi' && $node->hasField('field_tslk_oneri_taslak') && !$node->get('field_tslk_oneri_taslak')->isEmpty()) {
    $article = $node->hasField('field_tslk_oneri_madde') ? $node->get('field_tslk_oneri_madde')->entity : NULL;
    $workspace_url = $article
      ? \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.workspace_article', ['draft' => $node->get('field_tslk_oneri_taslak')->target_id, 'article' => $article->id()])
      : \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $node->get('field_tslk_oneri_taslak')->target_id]);
  }
  if ($workspace_url && \Drupal::currentUser()->hasPermission('access ayb drafting workspace')) {
    $toolbar['actions']['workspace'] = ['#type' => 'link', '#title' => t('Çalışma alanını aç'), '#url' => $workspace_url, '#attributes' => ['class' => ['button', 'ayb-draft-workspace-toolbar__button', 'ayb-draft-workspace-toolbar__button--secondary']]];
  }
  $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
  if ($state !== '') {
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($node);
    $state_label = $state;
    if ($workflow && $workflow->getTypePlugin()->hasState($state)) {
      $state_label = (string) $workflow->getTypePlugin()->getState($state)->label();
    }
    $toolbar['metadata']['state'] = ['#type' => 'html_tag', '#tag' => 'li', '#attributes' => ['class' => ['ayb-draft-workspace-toolbar__state']], '#value' => \Drupal\Component\Utility\Html::escape((string) t('Aşama: @state', ['@state' => $state_label]))];
  }
  if ($node->bundle() === 'mevzuat_taslagi' && $node->hasField('field_taslak_calisma_modeli')) {
    $model = $node->get('field_taslak_calisma_modeli')->value;
    $model_label = $model === 'madde_bazli' ? t('Madde bazlı') : t('Tam metin');
    $toolbar['metadata']['model'] = ['#type' => 'html_tag', '#tag' => 'li', '#attributes' => ['class' => ['ayb-draft-workspace-toolbar__model']], '#value' => \Drupal\Component\Utility\Html::escape((string) t('Çalışma modeli: @model', ['@model' => $model_label]))];
  }
  $timestamp = $node->getRevisionCreationTime();
  if ($timestamp) {
    $toolbar['metadata']['updated'] = ['#type' => 'html_tag', '#tag' => 'li', '#value' => \Drupal\Component\Utility\Html::escape((string) t('Son kayıt: @date', ['@date' => \Drupal::service('date.formatter')->format($timestamp, 'custom', 'd.m.Y')]))];
  }
  if ($node->getRevisionId()) {
    $toolbar['metadata']['revision'] = ['#type' => 'html_tag', '#tag' => 'li', '#value' => \Drupal\Component\Utility\Html::escape((string) t('Sürüm @revision', ['@revision' => $node->getRevisionId()]))];
  }
  $revision_author = $node->getRevisionUser();
  if ($revision_author) {
    $toolbar['metadata']['author'] = ['#type' => 'html_tag', '#tag' => 'li', '#value' => \Drupal\Component\Utility\Html::escape((string) t('Kaydeden: @name', ['@name' => $revision_author->getDisplayName()]))];
  }
  $completion_fields = $node->bundle() === 'mevzuat_taslagi'
    ? ['title', 'field_taslak_senaryosu', 'field_taslak_turu', 'field_taslak_calisma_modeli', 'field_taslak_metin', 'field_taslak_genel_gerekce']
    : ($node->bundle() === 'mevzuat_taslak_maddesi'
      ? ['title', 'field_taslak_madde_numarasi', 'field_taslak_madde_oneri_metin']
      : ($node->bundle() === 'mevzuat_taslak_onerisi'
        ? ['title', 'field_tslk_oneri_madde', 'field_tslk_oneri_yeni', 'field_tslk_oneri_gerekce']
        : ['title', 'field_taslak_gorus_taslak', 'field_taslak_gorus_metni']));
  $completion_fields = array_values(array_filter($completion_fields, fn($field_name): bool => $field_name === 'title' || $node->hasField($field_name)));
  $completed = 0;
  foreach ($completion_fields as $field_name) {
    $completed += $field_name === 'title' ? (int) (trim((string) $node->label()) !== '') : (int) !$node->get($field_name)->isEmpty();
  }
  $toolbar['metadata']['completion'] = [
    '#type' => 'html_tag', '#tag' => 'li',
    '#attributes' => ['class' => ['ayb-draft-workspace-toolbar__completion']],
    '#value' => \Drupal\Component\Utility\Html::escape((string) t('Temel alanlar: @done/@total', ['@done' => $completed, '@total' => count($completion_fields)])),
  ];
  if ($node->access('update')) {
    $edit_title = match ($node->bundle()) {
      'mevzuat_taslagi' => t('Taslağı düzenle'),
      'mevzuat_taslak_gorusu' => t('Görüş kaydını düzenle'),
      'mevzuat_taslak_onerisi' => t('Öneriyi düzenle'),
      default => t('Maddeyi düzenle'),
    };
    $toolbar['actions']['edit'] = ['#type' => 'link', '#title' => $edit_title, '#url' => $node->toUrl('edit-form', ['query' => ['destination' => \Drupal::request()->getRequestUri()]]), '#attributes' => ['class' => ['button', 'ayb-draft-workspace-toolbar__button']]];
  }
  if ($node->bundle() === 'mevzuat_taslagi' && $node->access('view')) {
    if ($node->isPublished()) {
      $toolbar['actions']['public_snapshots'] = [
        '#type' => 'link', '#title' => t('Önceki yayımlanmış sürümler'),
        '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.public_snapshot_list', ['draft' => $node->id()]),
        '#attributes' => ['class' => ['button', 'ayb-draft-workspace-toolbar__button', 'ayb-draft-workspace-toolbar__button--secondary']],
      ];
    }
    if ($account->hasPermission('access ayb drafting workspace')) {
      $toolbar['actions']['snapshots'] = [
        '#type' => 'link', '#title' => t('Editoryal sürümler'),
        '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.snapshot_list', ['draft' => $node->id()]),
        '#attributes' => ['class' => ['button', 'ayb-draft-workspace-toolbar__button', 'ayb-draft-workspace-toolbar__button--secondary']],
      ];
    }
    $toolbar['actions']['preview'] = [
      '#type' => 'link', '#title' => t('Önizlemeyi aç'),
      '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview', ['node' => $node->id()]),
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'ayb-draft-workspace-toolbar__button', 'ayb-draft-workspace-toolbar__button--preview'],
        'data-dialog-type' => 'modal',
    'data-dialog-options' => json_encode(['width' => 1120, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-preview-dialog']], JSON_UNESCAPED_SLASHES),
        'aria-label' => t('Taslağın güncel halini önizle'),
      ],
    ];
    $toolbar['#attached']['library'][] = 'core/drupal.dialog.ajax';
  }
  $account = \Drupal::currentUser();
  if ($account->hasPermission('view all revisions')) {
    $dialog_attributes = [
      'class' => ['use-ajax', 'button', 'ayb-draft-workspace-toolbar__button', 'ayb-draft-workspace-toolbar__button--secondary'],
      'data-dialog-type' => 'modal',
      'data-dialog-options' => json_encode(['width' => 1040, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-revision-dialog']], JSON_UNESCAPED_SLASHES),
    ];
    $toolbar['actions']['history'] = [
      '#type' => 'link', '#title' => t('Revizyon geçmişi'),
      '#url' => \Drupal\Core\Url::fromRoute('entity.node.version_history', ['node' => $node->id()]),
      '#attributes' => $dialog_attributes + ['aria-label' => t('Revizyon geçmişini aç')],
    ];
    $can_compare = in_array($node->bundle(), ['mevzuat_taslak_maddesi', 'mevzuat_taslak_gorusu'], TRUE)
      || ($node->bundle() === 'mevzuat_taslagi' && $node->hasField('field_taslak_calisma_modeli') && $node->get('field_taslak_calisma_modeli')->value === 'tam_metin');
    if ($can_compare) {
      $toolbar['actions']['compare'] = [
        '#type' => 'link', '#title' => t('Sürümleri karşılaştır'),
        '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.revision_compare', ['node' => $node->id()]),
        '#attributes' => $dialog_attributes + ['aria-label' => t('Sürümleri karşılaştır')],
      ];
    }
    $toolbar['#attached']['library'][] = 'core/drupal.dialog.ajax';
  }
  // The toolbar is the first incrementally migrated component. Its data
  // remains render-array based, while its stable markup is owned by Twig.
  $toolbar['#theme'] = 'ayb_mevzuat_calisma_workspace_toolbar';
  $toolbar['#kind'] = $bundle_labels[$node->bundle()] ?? t('Mevzuat çalışma kaydı');
  $toolbar['#metadata'] = $toolbar['metadata'];
  $toolbar['#actions'] = $toolbar['actions'];
  unset($toolbar['identity'], $toolbar['metadata'], $toolbar['actions']);
  CacheableMetadata::createFromObject($node)->applyTo($toolbar);
  return $toolbar;
}

/** Renders accessible child article records together on the parent draft page. */
function ayb_mevzuat_calisma_draft_article_text(NodeInterface $draft): array {
  $article_model = $draft->hasField('field_taslak_calisma_modeli') && $draft->get('field_taslak_calisma_modeli')->value === 'madde_bazli';
  $result = [
    '#type' => 'details',
    '#title' => $article_model ? t('Madde bazlı taslak metni') : t('İlişkili madde çalışmaları'),
    '#open' => TRUE,
    '#weight' => 1.5,
    '#attributes' => ['id' => 'ayb-draft-article-text', 'class' => ['ayb-draft-section', 'ayb-draft-article-text']],
    '#cache' => [
      'tags' => ['node_list'],
      'contexts' => ['user.permissions', 'user.node_grants:view', 'languages:language_content'],
    ],
  ];
  $ids = \Drupal::entityQuery('node')
    ->accessCheck(TRUE)
    ->condition('type', 'mevzuat_taslak_maddesi')
    ->condition('field_taslak_madde_taslak', $draft->id())
    ->execute();
  if (!$ids) {
    $result['#access'] = FALSE;
    return $result;
  }
  $children = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids);
  $children = array_values(array_filter($children, static fn(NodeInterface $child): bool => $child->access('view')));
  $ordered = ayb_mevzuat_calisma_hierarchy_flatten(ayb_mevzuat_calisma_hierarchy($draft, $children));
  $children = array_map(static fn(array $entry): NodeInterface => $entry['record'], $ordered);
  if (!$children) {
    $result['#access'] = FALSE;
    return $result;
  }
  $status_counts = ['draft' => 0, 'incelemede' => 0, 'published' => 0, 'arsiv' => 0];
  foreach ($children as $child) {
    $child_state = $child->hasField('moderation_state') && !$child->get('moderation_state')->isEmpty()
      ? (string) $child->get('moderation_state')->value
      : 'draft';
    if (isset($status_counts[$child_state])) {
      $status_counts[$child_state]++;
    }
  }
  $status_summary = t('@draft hazırlık · @review incelemede · @published yayında · @archive arşiv', [
    '@draft' => $status_counts['draft'],
    '@review' => $status_counts['incelemede'],
    '@published' => $status_counts['published'],
    '@archive' => $status_counts['arsiv'],
  ]);
  $result['#title'] = t('Madde madde taslak metni (@count kayıt) · @summary', ['@count' => count($children), '@summary' => $status_summary]);
  $view_builder = \Drupal::entityTypeManager()->getViewBuilder('node');
  $result['#attached']['library'][] = 'core/drupal.dialog.ajax';
  $result['#attached']['library'][] = 'ayb_mevzuat_calisma/draft';
  $toc_items = [];
  foreach ($children as $index => $child) {
    $translated = \Drupal::service('entity.repository')->getTranslationFromContext($child);
    $record_type = $translated->get('field_taslak_kayit_turu')->value ?: 'madde';
    $section_title = (string) $translated->get('field_taslak_bolum_basligi')->value;
    $article_heading = (string) $translated->get('field_taslak_madde_basligi')->value;
    $heading = in_array($record_type, ['kitap', 'bolum', 'kisim', 'alt_baslik'], TRUE)
      ? ($section_title ?: $translated->label())
      : ($article_heading ?: $translated->label());
    $heading_tag = match ($record_type) {
      'kitap' => 'h2',
      'kisim' => 'h3',
      'bolum' => 'h4',
      'alt_baslik' => 'h5',
      default => 'h3',
    };
    $alignment_mode = $draft->hasField('field_taslak_baslik_hizalama') ? (string) $draft->get('field_taslak_baslik_hizalama')->value : 'varsayilan';
    $heading_alignment = $alignment_mode === 'sol' ? 'left' : ($alignment_mode === 'orta' ? 'center' : (in_array($record_type, ['kitap', 'bolum', 'kisim', 'alt_baslik'], TRUE) ? 'center' : 'left'));
    $card = [
      '#type' => 'html_tag',
      '#tag' => 'article',
      '#attributes' => ['id' => 'ayb-draft-article-' . $translated->id(), 'class' => ['ayb-draft-article-card', 'ayb-draft-article-card--' . $record_type, 'ayb-draft-article-card--align-' . $heading_alignment]],
      '#weight' => $index,
      'heading' => [
        '#type' => 'html_tag', '#tag' => $heading_tag,
        '#weight' => $record_type === 'madde' && $heading !== '' ? -2 : 0,
        'link' => ['#type' => 'link', '#title' => $heading, '#url' => $translated->toUrl()],
      ],
    ];
    $number_label = $record_type === 'madde'
      ? ayb_mevzuat_calisma_article_number_label($draft, (string) $translated->get('field_taslak_madde_numarasi')->value)
      : '';
    if ($number_label !== '') {
      $card['number'] = [
        '#type' => 'html_tag', '#tag' => 'div',
        '#attributes' => ['class' => ['ayb-draft-article-card__number']],
        '#value' => \Drupal\Component\Utility\Html::escape($number_label),
        '#weight' => -1,
      ];
    }
    if ($record_type === 'madde' && $section_title) {
      $card['section'] = [
        '#type' => 'html_tag', '#tag' => 'div',
        '#attributes' => ['class' => ['ayb-draft-article-card__section']],
        '#value' => \Drupal\Component\Utility\Html::escape($section_title),
        '#weight' => 0,
      ];
    }
    $child_state = $translated->hasField('moderation_state') && !$translated->get('moderation_state')->isEmpty()
      ? (string) $translated->get('moderation_state')->value
      : 'draft';
    $child_workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($translated);
    $child_state_label = $child_state;
    if ($child_workflow && $child_workflow->getTypePlugin()->hasState($child_state)) {
      $child_state_label = (string) $child_workflow->getTypePlugin()->getState($child_state)->label();
    }
    $card['meta'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-article-card__meta']],
      '#weight' => -2,
      'moderation' => [
        '#type' => 'html_tag', '#tag' => 'span',
        '#attributes' => ['class' => ['ayb-draft-article-card__moderation', 'ayb-draft-article-card__moderation--' . preg_replace('/[^a-z0-9_-]+/i', '-', $child_state)]],
        '#value' => \Drupal\Component\Utility\Html::escape((string) t('Madde durumu: @state', ['@state' => $child_state_label])),
      ],
    ];
    $fields = [
      'field_taslak_madde_mevcut' => 'İlgili yürürlükteki madde',
      'field_taslak_madde_nihai_metin' => 'Nihai madde metni',
      'field_taslak_madde_gerekcesi' => 'Madde gerekçesi',
      'field_taslak_madde_karsilastirma' => 'Karşılaştırma ve açıklama',
      'field_taslak_madde_belge_media' => 'Madde belgeleri',
    ];
    if ($article_model) {
      unset($fields['field_taslak_madde_nihai_metin']);
    }
    foreach ($fields as $field_name => $label) {
      if (!$translated->hasField($field_name) || $translated->get($field_name)->isEmpty()) {
        continue;
      }
      $access = $translated->get($field_name)->access('view', NULL, TRUE);
      CacheableMetadata::createFromObject($access)->applyTo($result);
      if ($access->isAllowed()) {
        $card[$field_name] = $view_builder->viewField($translated->get($field_name), ['label' => 'above']);
        $card[$field_name]['#title'] = t($label);
        CacheableMetadata::createFromRenderArray($result)->merge(CacheableMetadata::createFromRenderArray($card[$field_name]))->applyTo($result);
      }
    }
    if ($article_model) {
      $source_field = NULL;
      if ($translated->hasField('field_taslak_madde_nihai_metin') && !$translated->get('field_taslak_madde_nihai_metin')->isEmpty()) {
        $source_field = 'field_taslak_madde_nihai_metin';
      }
      elseif ($translated->hasField('field_taslak_madde_oneri_metin') && !$translated->get('field_taslak_madde_oneri_metin')->isEmpty()) {
        $source_field = 'field_taslak_madde_oneri_metin';
      }
      if ($source_field && $translated->get($source_field)->access('view')) {
        $card['body'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-article-card__body']],
          'label' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $source_field === 'field_taslak_madde_nihai_metin' ? t('Nihai metin') : t('Önerilen metin')],
          'text' => $view_builder->viewField($translated->get($source_field), ['label' => 'hidden']),
        ];
      }
      else {
        $card['body'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ayb-draft-article-card__body', 'ayb-draft-article-card__body--empty']],
          'text' => ['#plain_text' => t('Bu madde için henüz öneri veya nihai metin girilmedi.')],
        ];
      }
    }
    if ($translated->access('update')) {
      $card['meta']['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-article-card__actions'], 'aria-label' => t('Madde işlemleri')],
        'edit' => [
          '#type' => 'link',
          '#title' => '↗',
          '#url' => $translated->toUrl('edit-form'),
          '#attributes' => ['class' => ['button', 'button--small', 'ayb-draft-article-card__edit'], 'title' => t('Maddeyi tam düzenle'), 'aria-label' => t('Maddeyi tam düzenle: @title', ['@title' => $heading])],
        ],
        'quick_edit' => [
          '#type' => 'link',
          '#title' => '✎',
          '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_article_quick_edit', ['node' => $translated->id()]),
          '#attributes' => [
            'class' => ['use-ajax', 'button', 'button--small', 'ayb-draft-article-card__quick-edit'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => json_encode(['width' => 980, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-quick-edit-dialog']], JSON_UNESCAPED_SLASHES),
            'title' => t('Hızlı düzenle'),
            'aria-label' => t('Maddeyi hızlı düzenle: @title', ['@title' => $heading]),
          ],
        ],
      ];
    }
    $old_field = 'field_taslak_madde_mevcut_metin';
    $new_field = 'field_taslak_madde_oneri_metin';
    if (($translated->hasField($old_field) && !$translated->get($old_field)->isEmpty())
      || ($translated->hasField($new_field) && !$translated->get($new_field)->isEmpty())) {
      $card['comparison'] = ayb_mevzuat_calisma_draft_comparison($translated, $old_field, $new_field, 'Mevcut metin', 'Önerilen metin');
    }
    CacheableMetadata::createFromObject($translated)->applyTo($result);
    $result['record_' . $translated->id()] = $card;
    $toc_label = $heading;
    if ($number_label !== '') {
      $toc_label = $number_label . ' · ' . $heading;
    }
    $toc_level = match ($record_type) {
      'kitap' => 0,
      'kisim' => 1,
      'bolum' => 2,
      'alt_baslik' => 3,
      default => 4,
    };
    $toc_items[] = [
      '#type' => 'link',
      '#title' => $toc_label,
      '#url' => \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $draft->id()], ['fragment' => 'ayb-draft-article-' . $translated->id()]),
      '#attributes' => ['class' => ['ayb-draft-toc__link', 'ayb-draft-toc__link--level-' . $toc_level]],
    ];
  }
  $result['toc'] = [
    '#type' => 'details',
    '#title' => t('İçindekiler'),
    '#open' => TRUE,
    '#weight' => -1,
    '#attributes' => ['class' => ['ayb-draft-toc']],
    'links' => ['#theme' => 'item_list', '#items' => $toc_items, '#attributes' => ['class' => ['ayb-draft-toc__list']]],
  ];
  return $result;
}

/** Renders two accessible text fields side by side, followed by their inline diff. */
function ayb_mevzuat_calisma_draft_comparison(NodeInterface $node, string $old_field, string $new_field, string $old_label, string $new_label): array {
  $comparison = [
    '#type' => 'container',
    '#attributes' => ['class' => ['ayb-draft-comparison']],
    '#weight' => 1,
    'columns' => ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-comparison__columns']]],
  ];
  $view_builder = \Drupal::entityTypeManager()->getViewBuilder('node');
  foreach ([[$old_field, $old_label, 'old'], [$new_field, $new_label, 'new']] as [$field_name, $label, $key]) {
    $comparison['columns'][$key] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-comparison__column', 'ayb-draft-comparison__column--' . $key]],
      'label' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $label],
    ];
    if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
      $access = $node->get($field_name)->access('view', NULL, TRUE);
      CacheableMetadata::createFromObject($access)->applyTo($comparison);
      if ($access->isAllowed()) {
        $comparison['columns'][$key]['content'] = $view_builder->viewField($node->get($field_name), ['label' => 'hidden']);
      }
    }
    else {
      $comparison['columns'][$key]['content'] = ['#plain_text' => t('Henüz metin girilmedi.')];
    }
  }
  if ($node->hasField($old_field) && $node->hasField($new_field)
    && !$node->get($old_field)->isEmpty() && !$node->get($new_field)->isEmpty()
    && $node->get($old_field)->access('view') && $node->get($new_field)->access('view')) {
    $comparison['diff'] = ayb_mevzuat_calisma_draft_diff(
      (string) $node->get($old_field)->value,
      (string) $node->get($new_field)->value,
    );
  }
  CacheableMetadata::createFromObject($node)->applyTo($comparison);
  return $comparison;
}

/** Builds parent-linked article/opinion lists with normal entity access checks. */
function ayb_mevzuat_calisma_draft_relations(NodeInterface $draft): array {
  $result = ['#type' => 'container', '#attributes' => ['id' => 'ayb-draft-relations', 'class' => ['ayb-draft-relations']], '#weight' => 8, '#cache' => ['tags' => ['node_list'], 'contexts' => ['user', 'user.permissions', 'user.node_grants:view', 'languages:language_content']]];
  foreach ([
    ['mevzuat_taslak_maddesi', 'field_taslak_madde_taslak', 'Madde çalışmaları'],
    ['mevzuat_taslak_gorusu', 'field_taslak_gorus_taslak', 'Görüşler ve değerlendirmeler'],
  ] as [$bundle, $parent, $label]) {
    $query = \Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type', $bundle)->condition($parent, $draft->id());
    $total = (clone $query)->count()->execute();
    $ids = $query->sort($bundle === 'mevzuat_taslak_maddesi' ? 'field_taslak_madde_sira' : 'created', $bundle === 'mevzuat_taslak_maddesi' ? 'ASC' : 'DESC')->range(0, 20)->execute();
    $items = [];
    foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids) as $child) {
      CacheableMetadata::createFromRenderArray($result)->merge(CacheableMetadata::createFromObject($child))->applyTo($result);
      $access = $child->access('view', NULL, TRUE);
      CacheableMetadata::createFromRenderArray($result)->merge(CacheableMetadata::createFromObject($access))->applyTo($result);
      if ($access->isAllowed()) {
        $translated = \Drupal::service('entity.repository')->getTranslationFromContext($child);
        $item_label = $translated->label();
        $metadata = [];
        if ($bundle === 'mevzuat_taslak_maddesi') {
          $number = $translated->get('field_taslak_madde_numarasi')->value;
          $section = $translated->get('field_taslak_bolum_basligi')->value;
          if ($number) {
            $number_label = ayb_mevzuat_calisma_article_number_label($draft, (string) $number);
            $item_label = $number_label !== '' ? $number_label . ' · ' . $item_label : $item_label;
          }
          if ($section) {
            $metadata[] = $section;
          }
          foreach (['field_taslak_madde_deg_turu' => 'Değişiklik türü', 'field_taslak_madde_durum' => 'Durum'] as $field_name => $field_label) {
            if (!$translated->get($field_name)->isEmpty()) {
              $value = $translated->get($field_name)->value;
              $allowed = $translated->get($field_name)->getFieldDefinition()->getSetting('allowed_values');
              $metadata[] = t('@label: @value', ['@label' => $field_label, '@value' => $allowed[$value] ?? $value]);
            }
          }
        }
        else {
          if ($translated->get('field_taslak_gorus_sunan')->value) {
            $metadata[] = $translated->get('field_taslak_gorus_sunan')->value;
          }
          if (!$translated->get('field_taslak_gorus_sonucu')->isEmpty()) {
            $value = $translated->get('field_taslak_gorus_sonucu')->value;
            $allowed = $translated->get('field_taslak_gorus_sonucu')->getFieldDefinition()->getSetting('allowed_values');
            $metadata[] = $allowed[$value] ?? $value;
          }
        }
        $item = [
          '#type' => 'container', '#attributes' => ['class' => ['ayb-draft-related-item']],
          'link' => ['#type' => 'link', '#title' => $item_label, '#url' => \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $translated->id()])],
          'metadata' => $metadata ? ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-related-item__meta']], '#value' => \Drupal\Component\Utility\Html::escape(implode(' · ', array_map(static fn($value) => (string) $value, $metadata)))] : [],
        ];
        if ($translated->access('update')) {
          $edit_label = $bundle === 'mevzuat_taslak_maddesi' ? t('Maddeyi düzenle') : t('Görüş kaydını düzenle');
          $item['edit'] = [
            '#type' => 'link', '#title' => $edit_label, '#url' => $translated->toUrl('edit-form', ['query' => ['destination' => \Drupal::request()->getRequestUri()]]),
            '#attributes' => ['class' => ['button', 'button--small', 'ayb-draft-related-item__edit'], 'aria-label' => $edit_label . ': ' . $item_label],
          ];
        }
        $items[] = $item;
      }
    }
    $all_route = $bundle === 'mevzuat_taslak_maddesi' ? 'ayb_mevzuat_calisma.workspace_draft' : 'ayb_mevzuat_calisma.workspace_consolidation';
    $all_title = $bundle === 'mevzuat_taslak_maddesi' ? t('Belge ağacında görüntüle') : t('Görüş ve önerileri incele');
    $can_open_all = $bundle === 'mevzuat_taslak_maddesi'
      ? \Drupal::currentUser()->hasPermission('access ayb drafting workspace')
      : (\Drupal::currentUser()->hasPermission('manage ayb draft suggestions') || \Drupal::currentUser()->hasPermission('administer ayb legal information'));
    $result[$bundle] = ['#type' => 'details', '#title' => t($label) . ' (' . $total . ')', '#open' => TRUE, '#attributes' => ['id' => 'ayb-draft-relation-' . ($bundle === 'mevzuat_taslak_maddesi' ? 'maddeler' : 'gorusler'), 'class' => ['ayb-draft-section', 'ayb-draft-section--related']],
      'items' => $items ? ['#theme' => 'item_list', '#items' => $items] : ['#type' => 'html_tag', '#tag' => 'p', '#value' => t('Henüz kayıt eklenmedi.')],
      'all' => ['#type' => 'link', '#access' => $can_open_all, '#title' => $all_title, '#url' => \Drupal\Core\Url::fromRoute($all_route, ['draft' => $draft->id()]), '#attributes' => ['class' => ['button', 'button--small', 'ayb-draft-related-item__all']]],
    ];
    if ($bundle === 'mevzuat_taslak_maddesi' && $draft->access('update')) {
      $result[$bundle]['order'] = [
        '#type' => 'link',
        '#title' => t('Çalışma sırasını düzenle'),
        '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_article_order', ['node' => $draft->id()]),
        '#attributes' => ['class' => ['button', 'ayb-draft-ordering-link']],
      ];
    }
    $create_access = \Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess($bundle, NULL, [], TRUE);
    CacheableMetadata::createFromRenderArray($result)->merge(CacheableMetadata::createFromObject($create_access))->applyTo($result);
    $is_article_model = $draft->hasField('field_taslak_calisma_modeli') && $draft->get('field_taslak_calisma_modeli')->value === 'madde_bazli';
    if ($create_access->isAllowed() && ($bundle !== 'mevzuat_taslak_maddesi' || $is_article_model)) {
      if ($bundle === 'mevzuat_taslak_maddesi' && $is_article_model) {
        $result[$bundle]['add'] = ['#type' => 'link', '#title' => t('Çalışma alanında yeni kayıt ekle'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.workspace_draft', ['draft' => $draft->id()]), '#attributes' => ['class' => ['button', 'ayb-draft-add', 'ayb-draft-add--workspace']]];
      }
      elseif ($bundle === 'mevzuat_taslak_gorusu') {
        $result[$bundle]['add'] = ['#type' => 'link', '#title' => t('Yeni görüş kaydı ekle'), '#url' => \Drupal\Core\Url::fromRoute('node.add', ['node_type' => $bundle], ['query' => ['taslak' => $draft->id(), 'destination' => \Drupal::request()->getRequestUri()]]), '#attributes' => ['class' => ['button', 'ayb-draft-add']]];
      }
    }
  }
  return $result;
}

/** Renders LEOS-style suggestions attached to an article. */
function ayb_mevzuat_calisma_draft_suggestions(NodeInterface $article): array {
  $result = [
    '#type' => 'container',
    '#weight' => 7,
    '#access' => \Drupal::currentUser()->hasPermission('access ayb drafting workspace') || \Drupal::currentUser()->hasPermission('administer ayb legal information'),
    '#attributes' => ['id' => 'ayb-draft-suggestions', 'class' => ['ayb-draft-suggestions']],
    '#cache' => ['contexts' => ['user.permissions'], 'tags' => ['node:' . $article->id(), 'node_list']],
    'heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => t('Madde önerileri')],
  ];
  $ids = \Drupal::entityQuery('node')->accessCheck(TRUE)
    ->condition('type', 'mevzuat_taslak_onerisi')
    ->condition('field_tslk_oneri_madde', $article->id())
    ->sort('created', 'DESC')->execute();
  $items = [];
  foreach ($ids ? \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids) : [] as $suggestion) {
    if (!$suggestion->access('view')) {
      continue;
    }
    $status = (string) $suggestion->get('field_tslk_oneri_durum')->value;
    $status_values = $suggestion->get('field_tslk_oneri_durum')->getFieldDefinition()->getSetting('allowed_values');
    $type = (string) $suggestion->get('field_tslk_oneri_turu')->value;
    $type_values = $suggestion->get('field_tslk_oneri_turu')->getFieldDefinition()->getSetting('allowed_values');
    $card = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-suggestion', 'ayb-draft-suggestion--' . preg_replace('/[^a-z0-9_-]+/i', '-', $status)]],
      'header' => [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-draft-suggestion__header']],
        'title' => $suggestion->toLink()->toRenderable(),
        'meta' => ['#plain_text' => ($type_values[$type] ?? $type) . ' · ' . ($status_values[$status] ?? $status)],
      ],
      'reason' => ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-suggestion__reason']], '#value' => \Drupal\Component\Utility\Html::escape((string) $suggestion->get('field_tslk_oneri_gerekce')->value)],
    ];
    if (!$suggestion->get('field_tslk_oneri_eski')->isEmpty()) {
      $card['old'] = ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-suggestion__old']], '#value' => '<strong>' . t('Mevcut') . ':</strong> ' . \Drupal\Component\Utility\Html::escape((string) $suggestion->get('field_tslk_oneri_eski')->value)];
    }
    $card['new'] = ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-suggestion__new']], '#value' => '<strong>' . t('Öneri') . ':</strong> ' . \Drupal\Component\Utility\Html::escape((string) $suggestion->get('field_tslk_oneri_yeni')->value)];
    if (in_array($status, ['acik', 'inceleniyor'], TRUE) && \Drupal::currentUser()->hasPermission('manage ayb draft suggestions')) {
      $card['actions'] = [
        '#type' => 'container', '#attributes' => ['class' => ['ayb-draft-suggestion__actions']],
        'accept' => ['#type' => 'link', '#title' => t('Kabul et ve uygula'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.suggestion_decision', ['suggestion' => $suggestion->id(), 'decision' => 'accept']), '#attributes' => ['class' => ['button', 'button--primary', 'use-ajax'], 'data-dialog-type' => 'modal', 'data-dialog-options' => json_encode(['width' => 620, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-decision-drawer']])]],
        'reject' => ['#type' => 'link', '#title' => t('Reddet'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.suggestion_decision', ['suggestion' => $suggestion->id(), 'decision' => 'reject']), '#attributes' => ['class' => ['button', 'button--danger', 'use-ajax'], 'data-dialog-type' => 'modal', 'data-dialog-options' => json_encode(['width' => 620, 'classes' => ['ui-dialog' => 'ayb-draft-drawer-dialog ayb-draft-decision-drawer']])]],
      ];
      $card['#attached']['library'][] = 'core/drupal.dialog.ajax';
    }
    $items[] = $card;
    CacheableMetadata::createFromObject($suggestion)->applyTo($result);
  }
  if (!$items) {
    $result['empty'] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-suggestions__empty']], '#value' => t('Bu madde için henüz öneri bulunmuyor.')];
  }
  else {
    $result['items'] = $items;
  }
  return $result;
}

/** Compares visible plain text using Drupal's bundled diff implementation. */
function ayb_mevzuat_calisma_draft_diff(string $from, string $to): array {
  $result = ['#type' => 'details', '#title' => t('Metin farklarını göster'), '#weight' => 7, '#attributes' => ['class' => ['ayb-draft-section', 'ayb-draft-diff']]];
  $comparison = \Drupal::service('ayb_mevzuat_calisma.diff')->compare($from, $to);
  if ($comparison['too_large']) {
    $result['note'] = ['#plain_text' => t('Bu metin için madde bazında karşılaştırma kullanın.')];
    return $result;
  }
  $result['legend'] = [
    '#theme' => 'ayb_mevzuat_calisma_diff_legend',
    '#delete_label' => t('Silinen metin'),
    '#insert_label' => t('Eklenen metin'),
  ];
  foreach ($comparison['edits'] as $i => $edit) {
    if ($edit->type === 'copy') {
      $result['line_' . $i] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => \Drupal\Component\Utility\Html::escape(implode("\n", $edit->orig))];
      continue;
    }
    $old_lines = is_array($edit->orig ?? NULL) ? array_values($edit->orig) : [];
    $new_lines = is_array($edit->closing ?? NULL) ? array_values($edit->closing) : [];
    $line_count = max(count($old_lines), count($new_lines));
    for ($line = 0; $line < $line_count; $line++) {
      $old_line = $old_lines[$line] ?? NULL;
      $new_line = $new_lines[$line] ?? NULL;
      if ($old_line !== NULL && $new_line !== NULL) {
        $result['inline_' . $i . '_' . $line] = [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ayb-diff-line', 'ayb-diff-line--changed']],
          '#value' => \Drupal::service('ayb_mevzuat_calisma.diff')->inlineDiff((string) $old_line, (string) $new_line),
        ];
      }
      elseif ($old_line !== NULL) {
        $result['delete_' . $i . '_' . $line] = [
          '#type' => 'html_tag', '#tag' => 'p',
          '#attributes' => ['class' => ['ayb-diff-line', 'ayb-diff-line--deleted']],
          '#value' => '<del class="ayb-diff-delete">' . \Drupal\Component\Utility\Html::escape((string) $old_line) . '</del>',
        ];
      }
      elseif ($new_line !== NULL) {
        $result['insert_' . $i . '_' . $line] = [
          '#type' => 'html_tag', '#tag' => 'p',
          '#attributes' => ['class' => ['ayb-diff-line', 'ayb-diff-line--added']],
          '#value' => '<ins class="ayb-diff-insert">' . \Drupal\Component\Utility\Html::escape((string) $new_line) . '</ins>',
        ];
      }
    }
  }
  return $result;
}

/** Creates a safe Unicode character-level diff for a pair of changed lines. */
function ayb_mevzuat_calisma_inline_diff(string $from, string $to): string {
  $tokenize = static function (string $text): array {
    return preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
  };
  $old = $tokenize($from);
  $new = $tokenize($to);

  // Preserve common edges first. This keeps small edits in long paragraphs
  // precise while bounding the quadratic comparison to the changed segment.
  $prefix = 0;
  $old_count = count($old);
  $new_count = count($new);
  while ($prefix < $old_count && $prefix < $new_count && $old[$prefix] === $new[$prefix]) {
    $prefix++;
  }
  $suffix = 0;
  while ($suffix < $old_count - $prefix && $suffix < $new_count - $prefix
    && $old[$old_count - $suffix - 1] === $new[$new_count - $suffix - 1]) {
    $suffix++;
  }
  $old_middle = array_slice($old, $prefix, $old_count - $prefix - $suffix);
  $new_middle = array_slice($new, $prefix, $new_count - $prefix - $suffix);
  $prefix_text = \Drupal\Component\Utility\Html::escape(implode('', array_slice($old, 0, $prefix)));
  $suffix_text = \Drupal\Component\Utility\Html::escape($suffix ? implode('', array_slice($old, -$suffix)) : '');

  // Avoid quadratic memory use for unusually large changed segments.
  if (count($old_middle) > 700 || count($new_middle) > 700) {
    return $prefix_text
      . '<del class="ayb-diff-delete">' . \Drupal\Component\Utility\Html::escape(implode('', $old_middle)) . '</del>'
      . '<ins class="ayb-diff-insert">' . \Drupal\Component\Utility\Html::escape(implode('', $new_middle)) . '</ins>'
      . $suffix_text;
  }
  $n = count($old_middle); $m = count($new_middle); $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
  for ($i = $n - 1; $i >= 0; $i--) {
    for ($j = $m - 1; $j >= 0; $j--) {
      $table[$i][$j] = $old_middle[$i] === $new_middle[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
    }
  }
  $html = $prefix_text; $i = 0; $j = 0; $deleted = ''; $inserted = '';
  $flush = static function () use (&$html, &$deleted, &$inserted): void {
    if ($deleted !== '') { $html .= '<del class="ayb-diff-delete">' . \Drupal\Component\Utility\Html::escape($deleted) . '</del>'; $deleted = ''; }
    if ($inserted !== '') { $html .= '<ins class="ayb-diff-insert">' . \Drupal\Component\Utility\Html::escape($inserted) . '</ins>'; $inserted = ''; }
  };
  while ($i < $n && $j < $m) {
    if ($old_middle[$i] === $new_middle[$j]) { $flush(); $html .= \Drupal\Component\Utility\Html::escape($old_middle[$i]); $i++; $j++; }
    elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) { $deleted .= $old_middle[$i++]; }
    else { $inserted .= $new_middle[$j++]; }
  }
  while ($i < $n) $deleted .= $old_middle[$i++];
  while ($j < $m) $inserted .= $new_middle[$j++];
  $flush();
  return $html . $suffix_text;
}
