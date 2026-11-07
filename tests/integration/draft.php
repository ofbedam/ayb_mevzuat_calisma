<?php

/** Run with drush php:script; all created records are removed in finally. */
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$created = [];
$checks = [];
$check = static function (string $name, bool $ok) use (&$checks): void {
  $checks[$name] = $ok;
  if (!$ok) {
    throw new \RuntimeException('FAILED: ' . $name);
  }
};
$valid = static function (Node $node): array {
  $errors = [];
  foreach ($node->validate() as $violation) {
    $errors[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
  }
  return $errors;
};
$save = static function (Node $node) use (&$created, $valid): Node {
  $errors = $valid($node);
  if ($errors) {
    throw new \RuntimeException(implode("\n", $errors));
  }
  $node->save();
  $created[] = (int) $node->id();
  return $node;
};
$prefix = 'AYB_AUTOTEST_20261026_';
try {
  $draft = $save(Node::create([
    'type' => 'mevzuat_taslagi', 'langcode' => 'tr', 'uid' => 1,
    'title' => $prefix . 'Taslak', 'moderation_state' => 'draft',
    'field_taslak_senaryosu' => 'yeni', 'field_taslak_versiyon' => '0.1',
    'field_taslak_mevcut_metin' => ['value' => '<p>Eski test metni.</p>', 'format' => 'basic_html'],
    'field_taslak_metin' => ['value' => '<p>Yeni test metni.</p>', 'format' => 'basic_html'],
    'field_taslak_genel_gerekce' => ['value' => '<p>Test gerekçesi.</p>', 'format' => 'basic_html'],
  ]));
  $check('new_draft_without_existing_law', $draft->id() > 0);
  $form_entity = clone $draft;
  if (\Drupal::moduleHandler()->moduleExists('book')) {
    // Replicate the node route's prepare-form initialization in the CLI test.
    $form_entity->book = [];
    book_node_prepare_form($form_entity, 'default', new \Drupal\Core\Form\FormState());
  }
  $form = \Drupal::service('entity.form_builder')->getForm($form_entity, 'default');
  $check('real_form_builds', isset($form['field_taslak_senaryosu']));
  $check('form_scenario_selector_name', ($form['field_taslak_senaryosu']['widget']['#name'] ?? '') === 'field_taslak_senaryosu');
  $check('form_conditional_existing_law', isset($form['field_taslak_mevzuat']['#states']['visible']));
  $view = \Drupal\views\Views::getView('ayb_mevzuat_taslaklari');
  $view->setDisplay('page_1');
  $view->execute();
  $check('unpublished_draft_in_management_view', in_array((int) $draft->id(), array_map(static fn($r) => (int) $r->nid, $view->result), TRUE));
  $view->destroy();
  $draft->set('field_taslak_senaryosu', 'degisiklik');
  $check('change_requires_existing_law', (bool) array_filter($valid($draft), static fn($e) => str_starts_with($e, 'field_taslak_mevzuat:')));
  $draft->set('field_taslak_senaryosu', 'yeni');
  $draft->set('field_taslak_calisma_modeli', 'madde_bazli');
  $draft->save();
  $draft->set('field_taslak_gorus_baslangic', '2026-10-26');
  $draft->set('field_taslak_gorus_bitis', '2026-10-25');
  $check('reversed_consultation_dates_rejected', (bool) array_filter($valid($draft), static fn($e) => str_starts_with($e, 'field_taslak_gorus_bitis:')));
  $draft->set('field_taslak_gorus_baslangic', NULL)->set('field_taslak_gorus_bitis', NULL);

  $article = $save(Node::create([
    'type' => 'mevzuat_taslak_maddesi', 'langcode' => 'tr', 'uid' => 1,
    'title' => $prefix . 'Madde', 'moderation_state' => 'draft',
    'field_taslak_madde_taslak' => $draft->id(), 'field_taslak_madde_numarasi' => '1', 'field_taslak_madde_sira' => 1,
    'field_taslak_madde_mevcut_metin' => ['value' => 'Eski madde.', 'format' => 'basic_html'],
    'field_taslak_madde_oneri_metin' => ['value' => 'Önce adli sonra.', 'format' => 'basic_html'],
  ]));
  $second_article = $save(Node::create([
    'type' => 'mevzuat_taslak_maddesi', 'langcode' => 'tr', 'uid' => 1,
    'title' => $prefix . 'İkinci Madde', 'moderation_state' => 'draft',
    'field_taslak_madde_taslak' => $draft->id(), 'field_taslak_madde_numarasi' => '2', 'field_taslak_madde_sira' => 20,
    'field_taslak_madde_oneri_metin' => ['value' => 'İkinci öneri.', 'format' => 'basic_html'],
  ]));
  $section = $save(Node::create([
    'type' => 'mevzuat_taslak_maddesi', 'langcode' => 'tr', 'uid' => 1,
    'title' => $prefix . 'Bölüm', 'moderation_state' => 'draft',
    'field_taslak_madde_taslak' => $draft->id(), 'field_taslak_kayit_turu' => 'bolum',
    'field_taslak_bolum_basligi' => 'Test Bölümü', 'field_taslak_madde_sira' => 30,
  ]));
  $workspace_controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\ayb_mevzuat_calisma\Controller\DraftWorkspaceController::class);
  $reorder_request = Request::create('/admin/content/mevzuat-taslaklari/' . $draft->id() . '/sira?_token=' . urlencode(\Drupal::service('csrf_token')->get('ayb_mevzuat_calisma.reorder')), 'POST', [], [], [], [], json_encode(['record' => $second_article->id(), 'before' => $article->id()]));
  $reorder_response = $workspace_controller->reorder($draft, $reorder_request);
  $article = Node::load($article->id());
  $second_article = Node::load($second_article->id());
  $check('tree_reorder_endpoint_saves_order', $reorder_response->getStatusCode() === 200 && (int) $second_article->get('field_taslak_madde_sira')->value < (int) $article->get('field_taslak_madde_sira')->value);
  $parent_request = Request::create('/admin/content/mevzuat-taslaklari/' . $draft->id() . '/sira?_token=' . urlencode(\Drupal::service('csrf_token')->get('ayb_mevzuat_calisma.reorder')), 'POST', [], [], [], [], json_encode(['record' => $second_article->id(), 'parent' => $section->id(), 'before' => 0]));
  $parent_response = $workspace_controller->reorder($draft, $parent_request);
  $second_article = Node::load($second_article->id());
  $check('tree_parent_move_endpoint_saves_parent', $parent_response->getStatusCode() === 200 && (int) $second_article->get('field_taslak_ust_kayit')->target_id === (int) $section->id());
  $lock_manager = \Drupal::service('ayb_mevzuat_calisma.lock');
  $check('draft_record_lock_acquired', $lock_manager->acquire($article) && $lock_manager->owns($article));
  $lock_manager->release($article);
  $check('draft_record_lock_released', $lock_manager->owns($article));
  $article->set('moderation_state', 'incelemede')->save();
  $second_article->set('moderation_state', 'incelemede')->save();
  $suggestion = $save(Node::create([
    'type' => 'mevzuat_taslak_onerisi', 'langcode' => 'tr', 'uid' => 1,
    'title' => $prefix . 'Öneri', 'status' => 0,
    'field_tslk_oneri_taslak' => $draft->id(), 'field_tslk_oneri_madde' => $article->id(),
    'field_tslk_oneri_turu' => 'dil', 'field_tslk_oneri_durum' => 'acik', 'field_tslk_oneri_alan' => 'oneri',
    'field_tslk_oneri_eski' => ['value' => 'adli', 'format' => 'basic_html'],
    'field_tslk_oneri_yeni' => ['value' => 'adlî', 'format' => 'basic_html'],
    'field_tslk_oneri_gerekce' => ['value' => 'Yazım birliği önerisi.', 'format' => 'basic_html'],
  ]));
  $check('suggestion_article_relation', (int) $suggestion->get('field_tslk_oneri_madde')->target_id === (int) $article->id());
  $decision = new \Drupal\ayb_mevzuat_calisma\Form\DraftSuggestionDecisionForm();
  $decision_build = [];
  $decision_state = new \Drupal\Core\Form\FormState();
  $decision->buildForm($decision_build, $decision_state, $suggestion, 'accept');
  $decision->submitForm($decision_build, $decision_state);
  $article = Node::load($article->id());
  $suggestion = Node::load($suggestion->id());
  $check('suggestion_accept_applies_text', str_contains((string) $article->get('field_taslak_madde_oneri_metin')->value, 'adlî') && $suggestion->get('field_tslk_oneri_durum')->value === 'kabul');
  $check('suggestion_preserves_surrounding_text', $article->get('field_taslak_madde_oneri_metin')->value === 'Önce adlî sonra.');
  $opinion = $save(Node::create([
    'type' => 'mevzuat_taslak_gorusu', 'langcode' => 'tr', 'uid' => 1,
    'title' => $prefix . 'Görüş', 'moderation_state' => 'draft',
    'field_taslak_gorus_taslak' => $draft->id(), 'field_taslak_gorus_madde' => $article->id(),
    'field_taslak_gorus_sunan_turu' => 'akademi', 'field_taslak_gorus_metni' => ['value' => 'Görüş.', 'format' => 'basic_html'],
    'field_taslak_gorus_yayin_durumu' => 'gizli',
  ]));
  $check('article_opinion_relations', (int) $opinion->get('field_taslak_gorus_madde')->target_id === (int) $article->id());
  $check('draft_delete_blocked_by_related_records', ayb_mevzuat_calisma_node_access($draft, 'delete', \Drupal::currentUser())->isForbidden());
  $check('article_delete_blocked_by_opinion_or_suggestion', ayb_mevzuat_calisma_node_access($article, 'delete', \Drupal::currentUser())->isForbidden());
  $workspace_build = $workspace_controller->draft($draft);
  $workspace_html = (string) \Drupal::service('renderer')->renderInIsolation($workspace_build);
  $check('workspace_draft_renders', str_contains($workspace_html, 'ayb-legislation-workspace') && str_contains($workspace_html, 'Taslak yapısı'));
  $check('workspace_tree_contains_article', str_contains($workspace_html, 'Madde 1'));
  $article_workspace_build = $workspace_controller->article($draft, $article);
  $article_workspace_html = (string) \Drupal::service('renderer')->renderInIsolation($article_workspace_build);
  $check('workspace_article_renders', str_contains($article_workspace_html, 'ayb-legislation-workspace__document--article') && str_contains($article_workspace_html, 'Madde metni'));
  $check('workspace_comparison_is_not_reported_empty', !str_contains($article_workspace_html, 'Bu madde için henüz metin girilmedi.'));
  $check('workspace_edit_actions_are_clear', str_contains($article_workspace_html, 'Metni ve gerekçeyi düzenle') && str_contains($article_workspace_html, 'Gelişmiş düzenleme') && str_contains($article_workspace_html, 'destination='));
  $check('workspace_edit_and_navigation_are_available', str_contains($article_workspace_html, 'data-workspace-edit') && str_contains($article_workspace_html, 'ayb-workspace-compare') && str_contains($article_workspace_html, 'ayb-workspace-edit'));
  $check('workspace_side_by_side_renders', str_contains($article_workspace_html, 'Mevcut ve önerilen metni yan yana karşılaştır') && str_contains($article_workspace_html, 'ayb-legislation-side-by-side__columns'));
  $check('workspace_diff_line_number_hook_renders', str_contains($article_workspace_html, 'data-sync-scroll-group'));
  $check('workspace_inspector_renders', str_contains($article_workspace_html, 'İnceleme ve işlemler') && str_contains($article_workspace_html, 'Bu madde için görüş ekle'));
  $consolidation_build = $workspace_controller->consolidation($draft, Request::create('/admin/content/mevzuat-taslaklari/' . $draft->id() . '/konsolidasyon'));
  $consolidation_html = (string) \Drupal::service('renderer')->renderInIsolation($consolidation_build);
  $check('consolidation_matrix_uses_theme', str_contains($consolidation_html, 'ayb-draft-consolidation') && str_contains($consolidation_html, 'Görüş ve öneri konsolidasyonu'));
  $check('consolidation_bulk_form_renders', str_contains($consolidation_html, 'ayb-draft-bulk-decision') && str_contains($consolidation_html, 'Seçilen kararları güncelle'));
  $check('workspace_valid_relation_allowed', $workspace_controller->articleAccess($draft, $article, \Drupal::currentUser())->isAllowed());
  $check('workspace_anonymous_denied', !$workspace_controller->draftAccess($draft, new \Drupal\Core\Session\AnonymousUserSession())->isAllowed());
  $workspace_url = \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.workspace_article', ['draft' => $draft->id(), 'article' => $article->id()])->toString();
  $check('workspace_route_generated', str_contains($workspace_url, '/admin/content/mevzuat-taslaklari/' . $draft->id() . '/madde/' . $article->id()));
  $opinion->set('field_taslak_gorus_taslak', 262);
  $check('mismatched_opinion_parent_rejected', (bool) array_filter($valid($opinion), static fn($e) => str_starts_with($e, 'field_taslak_gorus_madde:')));
  $opinion->set('field_taslak_gorus_taslak', $draft->id());
  $opinion->set('field_taslak_gorus_sonucu', 'reddedildi');
  $check('opinion_decision_requires_response', (bool) array_filter($valid($opinion), static fn($e) => str_starts_with($e, 'field_taslak_gorus_cevabi:')));
  $opinion->set('field_taslak_gorus_cevabi', ['value' => 'Gerekçeli cevap.', 'format' => 'basic_html']);
  $check('reasoned_opinion_valid', !$valid($opinion));
  // Test access even if a confidential opinion is accidentally published.
  $opinion->set('moderation_state', 'published')->save();
  $check('confidential_opinion_anonymous_access_denied', !$opinion->access('view', new \Drupal\Core\Session\AnonymousUserSession(), TRUE)->isAllowed());

  foreach (['incelemede', 'goruste', 'revize'] as $state) {
    $draft->setNewRevision(TRUE);
    $draft->set('moderation_state', $state);
    $check('transition_' . $state, !$valid($draft));
    $draft->save();
  }
  $draft->set('moderation_state', 'onaylandi');
  $approval_errors = $valid($draft);
  $check('approval_requires_article_final_text_and_decision', (bool) array_filter($approval_errors, static fn($e) => str_starts_with($e, 'field_taslak_calisma_modeli:')) && (bool) array_filter($approval_errors, static fn($e) => str_starts_with($e, 'field_taslak_onay_notu:')));
  $draft->set('field_taslak_nihai_metin', ['value' => 'Nihai metin.', 'format' => 'basic_html']);
  $draft->set('field_taslak_onay_notu', ['value' => 'Onay gerekçesi.', 'format' => 'basic_html']);
  $article->set('field_taslak_madde_nihai_metin', ['value' => 'Nihai madde metni.', 'format' => 'basic_html']);
  $second_article->set('field_taslak_madde_nihai_metin', ['value' => 'İkinci nihai madde metni.', 'format' => 'basic_html'])->save();
  $article->set('moderation_state', 'published')->save();
  $second_article->set('moderation_state', 'published')->save();
  $check('approval_with_final_text_valid', !$valid($draft));
  $draft->save();
  $draft->setNewRevision(TRUE);
  $draft->set('moderation_state', 'published');
  $check('approved_publication_valid', !$valid($draft));
  $draft->save();
  $preview_controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\ayb_mevzuat_calisma\Controller\DraftPreviewController::class);
  $document_method = new \ReflectionMethod($preview_controller, 'documentData');
  $text_document = $document_method->invoke($preview_controller, $draft, 'text');
  $reason_document = $document_method->invoke($preview_controller, $draft, 'reasons');
  $all_document = $document_method->invoke($preview_controller, $draft, 'all');
  $titles = static fn(array $document): array => array_column($document['sections'], 'title');
  $check('export_text_excludes_supporting_sections', !in_array('Genel gerekçe', $titles($text_document), TRUE) && !str_contains(implode(' ', $titles($text_document)), 'Görüş:'));
  $check('export_reasons_include_general_reason', in_array('Genel gerekçe', $titles($reason_document), TRUE) && !str_contains(implode(' ', $titles($reason_document)), 'Görüş:'));
  $check('export_all_includes_authorized_opinions', str_contains(implode(' ', $titles($all_document)), 'Görüş:'));
  $preview_build = $preview_controller->preview($draft);
  $preview_html = (string) \Drupal::service('renderer')->renderInIsolation($preview_build);
  $check('preview_cache_has_user_context', in_array('user', $preview_build['#cache']['contexts'] ?? [], TRUE));
  $check('preview_cache_has_parent_tag', in_array('node:' . $draft->id(), $preview_build['#cache']['tags'] ?? [], TRUE));
  $check('preview_paper_theme_renders', str_contains($preview_html, 'ayb-draft-preview-paper') && str_contains($preview_html, 'ayb-draft-preview-paper__title'));
  $check('preview_download_links_rendered', str_contains($preview_html, '/taslak-preview/download/word') && str_contains($preview_html, '/taslak-preview/download/pdf'));
  $word_response = $preview_controller->download($draft, 'word');
  $word_file = $word_response->getFile()->getPathname();
  $word_xml = '';
  if (class_exists(\ZipArchive::class)) {
    $zip = new \ZipArchive();
    if ($zip->open($word_file) === TRUE) {
      $word_xml = (string) $zip->getFromName('word/document.xml');
      $zip->close();
    }
  }
  $check('word_export_response_and_signature', $word_response->getStatusCode() === 200 && str_starts_with((string) file_get_contents($word_file), "PK\x03\x04"));
  $check('word_export_contains_structural_headings', str_contains($word_xml, 'Test Bölümü') && str_contains($word_xml, 'İkinci nihai madde metni'));
  $style_method = new \ReflectionMethod($preview_controller, 'wordSafeHtml');
  $style_method->setAccessible(TRUE);
  $styled_markup = (string) $style_method->invoke($preview_controller, '<p style="text-align: justify; line-height: 1.5;"><strong>Koyu metin</strong> ve hizalı metin.</p>');
  $check('word_export_preserves_safe_text_styles', str_contains($styled_markup, 'style="text-align: justify; line-height: 1.5"') && str_contains($styled_markup, '<strong>Koyu metin</strong>'));
  $pdf_response = $preview_controller->download($draft, 'pdf');
  $pdf_bytes = (string) $pdf_response->getContent();
  $check('pdf_export_response_and_signature', $pdf_response->getStatusCode() === 200 && str_starts_with($pdf_bytes, '%PDF'));
  @unlink($word_file);
  $compare_form = \Drupal::formBuilder()->getForm('Drupal\\ayb_mevzuat_calisma\\Form\\DraftRevisionComparisonForm', $draft);
  $compare_html = (string) \Drupal::service('renderer')->renderInIsolation($compare_form);
  $check('revision_compare_form_renders', str_contains($compare_html, 'ayb-draft-revision-compare-form'));
  $check('revision_compare_field_selector_renders', str_contains($compare_html, 'compare_fields'));

  foreach (['ayb_mevzuat_taslaklari', 'ayb_taslak_maddeleri', 'ayb_taslak_gorusleri'] as $id) {
    $view = \Drupal\views\Views::getView($id);
    $view->setDisplay('page_1');
    $view->execute();
    $check('view_' . $id, !empty($view->result));
    $view->destroy();
  }
  $build = \Drupal::entityTypeManager()->getViewBuilder('node')->view($draft, 'full');
  $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
  $article_build = \Drupal::entityTypeManager()->getViewBuilder('node')->view($article, 'full');
  $article_html = (string) \Drupal::service('renderer')->renderInIsolation($article_build);
  $check('suggestion_panel_renders', str_contains($article_html, 'Madde önerileri') && str_contains($article_html, 'adlî'));
  $decision_form = \Drupal::formBuilder()->getForm('Drupal\\ayb_mevzuat_calisma\\Form\\DraftSuggestionDecisionForm', $suggestion, 'reject');
  $decision_html = (string) \Drupal::service('renderer')->renderInIsolation($decision_form);
  $check('suggestion_decision_form_renders', str_contains($decision_html, 'Öneriyi reddet'));
  $check('page_sections_rendered', str_contains($html, 'ayb-draft-section'));
  $check('section_navigation_uses_theme', str_contains($html, 'ayb-draft-section-nav'));
  $check('diff_legend_renders', str_contains($html . $article_html, 'ayb-diff-legend'));
  $check('page_wrapper_class', str_contains($html, 'ayb-draft-page'));
  $check('view_toolbar_rendered', str_contains($html, 'ayb-draft-workspace-toolbar'));
  $check('view_preview_link_rendered', str_contains($html, '/taslak-preview'));
  $check('drawer_dialog_options_rendered', str_contains($html, 'ayb-draft-drawer-dialog'));
  $check('view_revision_history_link_rendered', str_contains($html, '/revisions'));
  $quick_controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\ayb_mevzuat_calisma\Controller\DraftInlineEditController::class);
  $quick_build = $quick_controller->edit($article);
  $quick_html = (string) \Drupal::service('renderer')->renderInIsolation($quick_build);
  $check('quick_edit_form_renders', str_contains($quick_html, 'Madde içeriğini hızlı düzenle') && str_contains($quick_html, 'Kaydet ve kapat'));
  $check('diff_added_and_deleted', str_contains($html . $article_html, '<ins') && str_contains($html . $article_html, '<del'));
  $check('approved_view_hides_working_article_cards', !str_contains($html, $prefix . 'Madde'));
  $check('approved_view_hides_working_opinion_cards', !str_contains($html, $prefix . 'Görüş'));
  file_put_contents('/tmp/ayb-draft-preview.html', '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Taslak görünüm testi</title><style>body{font-family:Arial,sans-serif;background:#f4f7fa;margin:0;padding:32px}main{max-width:1440px;margin:auto}summary{display:list-item}a{color:#185a7d}h1{color:#173f5c}</style><style>' . file_get_contents(__DIR__ . '/../../css/draft.css') . '</style><main><h1>Adli Yardımlaşma Yönetmeliği Taslağı — görünüm testi</h1>' . $html . '</main></html>');
  $translation = $draft->addTranslation('en', ['title' => $prefix . 'English', 'field_taslak_metin' => ['value' => 'English draft.', 'format' => 'basic_html']]);
  $check('translation_shared_scenario', $translation->get('field_taslak_senaryosu')->value === 'yeni');
  $check('translation_independent_text', $translation->get('field_taslak_metin')->value !== $draft->get('field_taslak_metin')->value);
  $translation->save();
  $reloaded = Node::load($draft->id());
  $check('translation_saved', $reloaded->hasTranslation('en'));
  $snapshot_manager = \Drupal::service('ayb_mevzuat_calisma.snapshot');
  $article_state_before_snapshot = (string) Node::load($article->id())->get('moderation_state')->value;
  $editorial_snapshot = $snapshot_manager->create($draft, $prefix . 'Snapshot A', 'Editoryal test snapshot.', 'editorial');
  $snapshot_payload = json_decode(\Drupal::database()->select('ayb_draft_snapshot', 's')->fields('s', ['payload'])->condition('snapshot_id', $editorial_snapshot)->execute()->fetchField(), TRUE);
  $check('editorial_snapshot_keeps_moderation_unchanged', Node::load($article->id())->get('moderation_state')->value === $article_state_before_snapshot && $snapshot_payload['articles'][0]['workflow'] === $article_state_before_snapshot);
  $check('editorial_snapshot_has_complete_article_tree', count($snapshot_payload['articles']) === 3);
  $second_editorial = $snapshot_manager->create($draft, $prefix . 'Snapshot B', 'İkinci editoryal nokta.', 'editorial');
  $snapshot_controller = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\ayb_mevzuat_calisma\Controller\DraftSnapshotController::class);
  $snapshot_list = $snapshot_controller->editorialList($draft);
  $check('editorial_snapshot_list_renders', isset($snapshot_list['table']['#rows']) && count($snapshot_list['table']['#rows']) >= 2);
  $snapshot_compare = $snapshot_controller->compare($editorial_snapshot, $second_editorial);
  $check('editorial_snapshot_compare_renders', isset($snapshot_compare['changes']));
  echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
finally {
  if (isset($draft) && $draft->id()) {
    \Drupal::database()->delete('ayb_draft_snapshot')->condition('draft_nid', $draft->id())->execute();
  }
  foreach (array_reverse(array_unique($created)) as $id) {
    if ($node = Node::load($id)) {
      $node->delete();
    }
  }
  $switcher->switchBack();
  echo 'Test kayıtları temizlendi: ' . implode(', ', $created) . "\n";
}
