<?php

namespace Drupal\ayb_mevzuat_calisma\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;
use PhpOffice\PhpWord\IOFactory;
use Mpdf\Mpdf;

/** Provides a document-like preview for a legislative draft. */
final class DraftPreviewController extends ControllerBase {

  /** Restricts the preview to legislative draft records. */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    $allowed = $node->bundle() === 'mevzuat_taslagi' && $node->access('view', $account);
    return AccessResult::allowedIf($allowed)
      ->addCacheableDependency($node)
      ->cachePerUser();
  }

  /** Builds the two selectable document previews. */
  public function preview(NodeInterface $node): array {
    $model = $node->hasField('field_taslak_calisma_modeli')
      ? (string) $node->get('field_taslak_calisma_modeli')->value
      : 'tam_metin';
    $children = $model === 'madde_bazli' ? $this->loadChildren($node) : [];
    $opinions = $this->loadOpinions($node);
    $article_preview = $this->articlePreview($node, $children);
    $general_preview = $this->generalPreview($node, $opinions);
    $clean_preview = $this->cleanWordPreview($node, $model, $children);
    $cache_tags = ['node:' . $node->id(), 'node_list'];
    foreach (array_merge($children, $opinions) as $related_entity) {
      $cache_tags[] = 'node:' . $related_entity->id();
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ayb-draft-preview-modal']],
      '#cache' => [
        'contexts' => ['user', 'user.permissions', 'user.node_grants:view', 'languages:language_content'],
        'tags' => array_values(array_unique($cache_tags)),
      ],
      '#attached' => [
        'library' => ['ayb_mevzuat_calisma/draft'],
        'drupalSettings' => ['aybMevzuatPreview' => ['node' => $node->id(), 'model' => $model]],
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-preview-modal__header']],
        'title' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $node->label()],
        'help' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Aşağıdaki üç görünümden birini açarak kaydın mevcut metin düzenini inceleyin. Önizleme son kaydedilmiş sürümü gösterir.')],
        'downloads' => [
          '#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-downloads'], 'aria-label' => $this->t('Dosya çıktıları')],
          'label' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('İndirme kapsamını seçin:')],
          'word' => ['#type' => 'link', '#title' => $this->t('Yalnız metin · Word'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview_download', ['node' => $node->id(), 'format' => 'word'], ['query' => ['scope' => 'text']]), '#attributes' => ['class' => ['ayb-draft-preview-download', 'ayb-draft-preview-download--word'], 'download' => '']],
          'pdf' => ['#type' => 'link', '#title' => $this->t('Yalnız metin · PDF'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview_download', ['node' => $node->id(), 'format' => 'pdf'], ['query' => ['scope' => 'text']]), '#attributes' => ['class' => ['ayb-draft-preview-download', 'ayb-draft-preview-download--pdf'], 'download' => '']],
          'reason_word' => ['#type' => 'link', '#title' => $this->t('Metin ve gerekçeler · Word'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview_download', ['node' => $node->id(), 'format' => 'word'], ['query' => ['scope' => 'reasons']]), '#attributes' => ['class' => ['ayb-draft-preview-download'], 'download' => '']],
          'reason_pdf' => ['#type' => 'link', '#title' => $this->t('Metin ve gerekçeler · PDF'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview_download', ['node' => $node->id(), 'format' => 'pdf'], ['query' => ['scope' => 'reasons']]), '#attributes' => ['class' => ['ayb-draft-preview-download'], 'download' => '']],
          'all_word' => ['#type' => 'link', '#title' => $this->t('Görüşler dahil · Word'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview_download', ['node' => $node->id(), 'format' => 'word'], ['query' => ['scope' => 'all']]), '#attributes' => ['class' => ['ayb-draft-preview-download'], 'download' => '']],
          'all_pdf' => ['#type' => 'link', '#title' => $this->t('Görüşler dahil · PDF'), '#url' => \Drupal\Core\Url::fromRoute('ayb_mevzuat_calisma.draft_preview_download', ['node' => $node->id(), 'format' => 'pdf'], ['query' => ['scope' => 'all']]), '#attributes' => ['class' => ['ayb-draft-preview-download'], 'download' => '']],
        ],
      ],
      'options' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ayb-draft-preview-options'], 'role' => 'tablist', 'aria-label' => $this->t('Önizleme seçenekleri')],
        'article' => [
          '#type' => 'html_tag', '#tag' => 'button', '#attributes' => ['type' => 'button', 'class' => ['ayb-draft-preview-option', 'ayb-draft-preview-option--article'], 'data-preview-target' => 'ayb-draft-preview-article', 'aria-controls' => 'ayb-draft-preview-article', 'aria-expanded' => $model === 'madde_bazli' ? 'true' : 'false'],
          '#value' => '<span class="ayb-draft-preview-option__icon" aria-hidden="true">§</span><span><strong>' . $this->t('Madde bazlı taslak') . '</strong><small>' . $this->t('Bölüm, kısım ve maddeleri belge düzeninde gösterir.') . '</small></span>',
        ],
        'general' => [
          '#type' => 'html_tag', '#tag' => 'button', '#attributes' => ['type' => 'button', 'class' => ['ayb-draft-preview-option', 'ayb-draft-preview-option--general'], 'data-preview-target' => 'ayb-draft-preview-general', 'aria-controls' => 'ayb-draft-preview-general', 'aria-expanded' => $model !== 'madde_bazli' ? 'true' : 'false'],
          '#value' => '<span class="ayb-draft-preview-option__icon" aria-hidden="true">¶</span><span><strong>' . $this->t('Genel metin ve gerekçeler') . '</strong><small>' . $this->t('Ana metni, gerekçeleri ve varsa görüşleri birlikte gösterir.') . '</small></span>',
        ],
        'clean' => [
          '#type' => 'html_tag', '#tag' => 'button', '#attributes' => ['type' => 'button', 'class' => ['ayb-draft-preview-option', 'ayb-draft-preview-option--clean'], 'data-preview-target' => 'ayb-draft-preview-clean', 'aria-controls' => 'ayb-draft-preview-clean', 'aria-expanded' => 'false'],
          '#value' => '<span class="ayb-draft-preview-option__icon" aria-hidden="true">▤</span><span><strong>' . $this->t('Temiz Word görünümü') . '</strong><small>' . $this->t('Alan etiketleri ve çalışma bilgileri olmadan sade belge görünümü.') . '</small></span>',
        ],
      ],
      'article_preview' => [
        '#type' => 'details', '#title' => $this->t('Madde bazlı taslak'), '#open' => $model === 'madde_bazli',
        '#attributes' => ['id' => 'ayb-draft-preview-article', 'class' => ['ayb-draft-preview-section', 'ayb-draft-preview-section--article']],
        'paper' => $article_preview,
      ],
      'general_preview' => [
        '#type' => 'details', '#title' => $this->t('Genel metin, gerekçe ve görüşler'), '#open' => $model !== 'madde_bazli',
        '#attributes' => ['id' => 'ayb-draft-preview-general', 'class' => ['ayb-draft-preview-section', 'ayb-draft-preview-section--general']],
        'paper' => $general_preview,
      ],
      'clean_preview' => [
        '#type' => 'details', '#title' => $this->t('Temiz Word görünümü'), '#open' => FALSE,
        '#attributes' => ['id' => 'ayb-draft-preview-clean', 'class' => ['ayb-draft-preview-section', 'ayb-draft-preview-section--clean']],
        'paper' => $clean_preview,
      ],
    ];
  }

  /** Downloads the clean preview as a UTF-8 Word or PDF document. */
  public function download(NodeInterface $node, string $format): Response {
    $scope = \Drupal::request()->query->get('scope', 'all');
    $scope = is_string($scope) && in_array($scope, ['text', 'reasons', 'all'], TRUE) ? $scope : 'all';
    $document = $this->documentData($node, $scope);
    $download_names = $this->downloadNames((string) $node->label());
    $temporary_directory = \Drupal::service('file_system')->getTempDirectory();
    if ($format === 'word') {
      $file = tempnam($temporary_directory, 'ayb-word-');
      $word = new PhpWord();
      $word->setDefaultFontName('Arial');
      $word->setDefaultFontSize(11);
      $section = $word->addSection(['marginTop' => 1440, 'marginBottom' => 1440, 'marginLeft' => 1440, 'marginRight' => 1440]);
      $html = '<h1 style="text-align:center;">' . Html::escape($document['title']) . '</h1><p style="text-align:center;color:#666;">' . Html::escape((string) $this->t('Taslak çalışma metni')) . '</p>' . $this->documentHeaderHtml($document);
      foreach ($document['sections'] as $section_data) {
        $alignment = $section_data['alignment'] ?? 'left';
        $tag = $section_data['tag'] ?? 'h2';
        $class = $section_data['class'] ?? 'ayb-export-heading';
        $number_html = !empty($section_data['number'])
          ? '<p class="ayb-article-number" style="text-align:' . $alignment . ';">' . Html::escape($section_data['number']) . '</p>'
          : '';
        $html .= '<' . $tag . ' class="' . Html::escape($class) . '" style="text-align:' . $alignment . ';">' . Html::escape($section_data['title']) . '</' . $tag . '>' . $number_html . $this->wordSafeHtml($section_data['html']);
      }
      PhpWordHtml::addHtml($section, '<div>' . $html . '</div>', FALSE, FALSE);
      IOFactory::createWriter($word, 'Word2007')->save($file);
      return (new BinaryFileResponse($file, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']))
        ->setContentDisposition('attachment', $download_names['utf8'] . '.docx', $download_names['ascii'] . '.docx')
        ->deleteFileAfterSend(TRUE);
    }
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temporary_directory, 'margin_top' => 22, 'margin_bottom' => 20, 'margin_left' => 22, 'margin_right' => 22]);
    $pdf_html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:dejavusans,sans-serif;color:#202020;font-size:11pt;line-height:1.75}h1{text-align:center;color:#173f5c;font-size:18pt;margin-bottom:4pt}h2{color:#185a7d;font-size:13pt;border-bottom:1px solid #185a7d;padding-bottom:5pt;margin-top:22pt}h3{color:#31566e;font-size:12pt;margin-top:18pt;margin-bottom:8pt}h4{color:#486b7d;font-size:11pt;margin-top:14pt;margin-bottom:6pt}.ayb-export-heading--kitap{letter-spacing:.04em;text-transform:uppercase}.ayb-export-heading--kisim,.ayb-export-heading--bolum{border-bottom:0;color:#31566e}.ayb-export-heading--alt_baslik,.ayb-export-heading--madde_basligi{border-bottom:0;color:#486b7d}.ayb-article-number{font-weight:700;color:#173f5c;margin:0 0 7pt}p{margin:0 0 9pt}.ayb-muted{text-align:center;color:#666;font-size:9pt}.ayb-document-meta{width:100%;border-collapse:collapse;margin:14pt 0 18pt;font-size:9pt}.ayb-document-meta td{border:1px solid #ccd8df;padding:5pt 7pt}.ayb-document-meta td:first-child{width:30%;font-weight:700;background:#f1f5f7;color:#31566e}</style></head><body><h1>' . Html::escape($document['title']) . '</h1><p class="ayb-muted">' . Html::escape((string) $this->t('Taslak çalışma metni')) . '</p>' . $this->documentHeaderHtml($document);
    foreach ($document['sections'] as $section_data) {
      $alignment = $section_data['alignment'] ?? 'left';
      $tag = $section_data['tag'] ?? 'h2';
      $class = $section_data['class'] ?? 'ayb-export-heading';
      $number_html = !empty($section_data['number'])
        ? '<p class="ayb-article-number" style="text-align:' . $alignment . ';">' . Html::escape($section_data['number']) . '</p>'
        : '';
      $pdf_html .= '<' . $tag . ' class="' . Html::escape($class) . '" style="text-align:' . $alignment . ';">' . Html::escape($section_data['title']) . '</' . $tag . '>' . $number_html . $this->wordSafeHtml($section_data['html']);
    }
    $mpdf->WriteHTML($pdf_html . '</body></html>');
    $pdf = $mpdf->Output('', 'S');
    $response = new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) strlen($pdf)]);
    $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $download_names['utf8'] . '.pdf', $download_names['ascii'] . '.pdf'));
    return $response;
  }

  /** Creates a safe UTF-8 filename and an ASCII fallback for older clients. */
  private function downloadNames(string $title): array {
    $base = preg_replace('~[\\\\/\x00-\x1F:*?"<>|]+~u', '-', trim($title)) ?: 'AYB mevzuat taslağı';
    $base = preg_replace('/\s+/u', ' ', $base) ?: 'AYB mevzuat taslağı';
    $base = trim($base, " .-");
    $fallback = strtr($base, [
      'ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
      'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U',
    ]);
    $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fallback) ?: 'ayb-mevzuat-taslagi';
    return ['utf8' => $base, 'ascii' => trim($fallback, '-.') ?: 'ayb-mevzuat-taslagi'];
  }

  /** Collects the same clean, UTF-8-safe content used by both downloads. */
  private function documentData(NodeInterface $draft, string $scope = 'all'): array {
    $model = $draft->hasField('field_taslak_calisma_modeli') ? (string) $draft->get('field_taslak_calisma_modeli')->value : 'tam_metin';
    $sections = [];
    if ($model === 'madde_bazli') {
      $ids = $this->entityTypeManager()->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'mevzuat_taslak_maddesi')->condition('field_taslak_madde_taslak', $draft->id())->execute();
      $children = $ids ? $this->entityTypeManager()->getStorage('node')->loadMultiple($ids) : [];
      $hierarchy = ayb_mevzuat_calisma_hierarchy($draft, $children);
      $ordered = ayb_mevzuat_calisma_hierarchy_flatten($hierarchy);
      foreach ($ordered as $entry) {
        $child = $entry['record'];
        if (!$child->access('view')) {
          continue;
        }
        $number = (string) $child->get('field_taslak_madde_numarasi')->value;
        $record_type = (string) ($child->get('field_taslak_kayit_turu')->value ?: 'madde');
        $is_structural = in_array($record_type, ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'], TRUE);
        $document_heading = $this->childDocumentHeading($child);
        $number_label = ayb_mevzuat_calisma_article_number_label($draft, $number);
        $title = trim($document_heading !== '' ? $document_heading : $number_label);
        $html = '';
        foreach (['field_taslak_madde_nihai_metin', 'field_taslak_madde_oneri_metin', 'field_taslak_madde_mevcut_metin'] as $field) {
          if ($child->hasField($field) && !$child->get($field)->isEmpty() && $child->get($field)->access('view')) {
            $html = $this->fieldHtml($child, $field);
            break;
          }
        }
        // Structural records are part of the legal document even when they do
        // not have body text of their own. Do not drop their headings from
        // Word/PDF merely because their child articles contain the text.
        if ($html !== '' || ($is_structural && $document_heading !== '')) {
          $sections[] = [
            'title' => (string) $title,
            'number' => $document_heading !== '' && !$is_structural ? $number_label : '',
            'html' => $html . ($scope !== 'text' ? $this->articleSupportingText($child) : ''),
            'alignment' => $this->headingAlignment($draft, $record_type),
            'tag' => $this->exportHeadingTag($record_type),
            'class' => 'ayb-export-heading ayb-export-heading--' . preg_replace('/[^a-z0-9_-]+/i', '-', $record_type),
          ];
        }
      }
    }
    else {
      foreach (['field_taslak_nihai_metin', 'field_taslak_metin', 'field_taslak_mevcut_metin'] as $field) {
        if ($draft->hasField($field) && !$draft->get($field)->isEmpty() && $draft->get($field)->access('view')) {
          $sections[] = ['title' => (string) $this->t('Metin'), 'html' => $this->fieldHtml($draft, $field), 'tag' => 'h2', 'class' => 'ayb-export-heading ayb-export-heading--text'];
          break;
        }
      }
    }
    if ($scope !== 'text') {
      foreach (['field_taslak_genel_gerekce' => $this->t('Genel gerekçe'), 'field_taslak_hukuki_dayanak' => $this->t('Hukukî dayanak')] as $field => $title) {
        if ($draft->hasField($field) && !$draft->get($field)->isEmpty() && $draft->get($field)->access('view')) {
          $sections[] = ['title' => (string) $title, 'html' => $this->fieldHtml($draft, $field), 'tag' => 'h2', 'class' => 'ayb-export-heading ayb-export-heading--context'];
        }
      }
    }
    if ($scope === 'all') {
    $opinion_ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_gorusu')
      ->condition('field_taslak_gorus_taslak', $draft->id())
      ->execute();
    foreach ($opinion_ids ? $this->entityTypeManager()->getStorage('node')->loadMultiple($opinion_ids) : [] as $opinion) {
      if (!$opinion->access('view')) {
        continue;
      }
      $opinion_html = '';
      foreach (['field_taslak_gorus_metni', 'field_taslak_gorus_onerisi', 'field_taslak_gorus_cevabi'] as $field) {
        if ($opinion->hasField($field) && !$opinion->get($field)->isEmpty() && $opinion->get($field)->access('view')) {
          $opinion_html .= $this->fieldHtml($opinion, $field);
        }
      }
      if ($opinion_html !== '') {
        $sections[] = ['title' => (string) $this->t('Görüş: @title', ['@title' => $opinion->label()]), 'html' => $opinion_html, 'tag' => 'h2', 'class' => 'ayb-export-heading ayb-export-heading--opinion'];
      }
    }
    $suggestion_ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_onerisi')
      ->condition('field_tslk_oneri_taslak', $draft->id())
      ->execute();
    foreach ($suggestion_ids ? $this->entityTypeManager()->getStorage('node')->loadMultiple($suggestion_ids) : [] as $suggestion) {
      if (!$suggestion->access('view')) {
        continue;
      }
      $suggestion_html = '';
      foreach (['field_tslk_oneri_eski' => 'Mevcut ifade', 'field_tslk_oneri_yeni' => 'Önerilen ifade', 'field_tslk_oneri_gerekce' => 'Öneri gerekçesi'] as $field => $label) {
        if ($suggestion->hasField($field) && !$suggestion->get($field)->isEmpty() && $suggestion->get($field)->access('view')) {
          $suggestion_html .= '<h3>' . Html::escape((string) $this->t($label)) . '</h3>' . $this->fieldHtml($suggestion, $field);
        }
      }
      if ($suggestion_html !== '') {
        $sections[] = ['title' => (string) $this->t('Madde önerisi: @title', ['@title' => $suggestion->label()]), 'html' => $suggestion_html, 'tag' => 'h2', 'class' => 'ayb-export-heading ayb-export-heading--suggestion'];
      }
    }
    }
    if (!$sections) {
      $sections[] = ['title' => (string) $this->t('İçerik'), 'html' => '<p>' . Html::escape((string) $this->t('Görüntülenecek metin bulunmuyor.')) . '</p>', 'tag' => 'h2', 'class' => 'ayb-export-heading ayb-export-heading--empty'];
    }
    return ['title' => (string) $draft->label(), 'metadata' => $this->documentMetadata($draft), 'sections' => $sections];
  }

  /** Builds compact official metadata for PDF and DOCX headers. */
  private function documentMetadata(NodeInterface $draft): array {
    $metadata = [];
    foreach ([
      'field_taslak_turu' => 'Taslak türü',
      'field_taslak_dosya_no' => 'Dosya numarası',
      'field_taslak_versiyon' => 'Sürüm',
      'field_taslak_hazirlayan' => 'Hazırlayan',
      'field_taslak_sorumlu_birim' => 'Sorumlu birim',
      'field_taslak_tarihi' => 'Taslak tarihi',
      'field_taslak_durumu' => 'Durum',
    ] as $field => $label) {
      if (!$draft->hasField($field) || $draft->get($field)->isEmpty() || !$draft->get($field)->access('view')) {
        continue;
      }
      $value = (string) ($draft->get($field)->value ?? '');
      $allowed = $draft->get($field)->getFieldDefinition()->getSetting('allowed_values') ?: [];
      $metadata[] = ['label' => (string) $this->t($label), 'value' => (string) ($allowed[$value] ?? $value)];
    }
    return $metadata;
  }

  /** Returns safe HTML metadata shared by Word and PDF output. */
  private function documentHeaderHtml(array $document): string {
    if (empty($document['metadata'])) {
      return '';
    }
    $html = '<table class="ayb-document-meta"><tbody>';
    foreach ($document['metadata'] as $item) {
      $html .= '<tr><td>' . Html::escape($item['label']) . '</td><td>' . Html::escape($item['value']) . '</td></tr>';
    }
    return $html . '</tbody></table>';
  }

  /** Appends article-level rationale and comparison information to exports. */
  private function articleSupportingText(NodeInterface $article): string {
    $html = '';
    foreach (['field_taslak_madde_gerekcesi' => 'Madde gerekçesi', 'field_taslak_madde_karsilastirma' => 'Karşılaştırma açıklaması'] as $field => $label) {
      if ($article->hasField($field) && !$article->get($field)->isEmpty() && $article->get($field)->access('view')) {
        $html .= '<h3>' . Html::escape((string) $this->t($label)) . '</h3>' . $this->fieldHtml($article, $field);
      }
    }
    return $html;
  }

  /** Loads article records once for the three in-browser preview variants. */
  private function loadChildren(NodeInterface $draft): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_maddesi')
      ->condition('field_taslak_madde_taslak', $draft->id())
      ->execute();
    $children = $ids ? $storage->loadMultiple($ids) : [];
    $ordered = ayb_mevzuat_calisma_hierarchy_flatten(ayb_mevzuat_calisma_hierarchy($draft, $children));
    return array_map(static fn(array $entry): NodeInterface => $entry['record'], $ordered);
  }

  /** Loads consultation records once for the three in-browser preview variants. */
  private function loadOpinions(NodeInterface $draft): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'mevzuat_taslak_gorusu')
      ->condition('field_taslak_gorus_taslak', $draft->id())
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /** Returns filtered field markup without losing Turkish characters. */
  private function fieldHtml(NodeInterface $entity, string $field): string {
    $html = '';
    foreach ($entity->get($field)->getValue() as $item) {
      $value = (string) ($item['value'] ?? '');
      $format = (string) ($item['format'] ?? 'basic_html');
      $html .= function_exists('check_markup') ? (string) check_markup($value, $format) : nl2br(Html::escape($value));
    }
    return $html;
  }

  /** Keeps article ordering consistent across preview and export outputs. */
  private function compareChildren(NodeInterface $a, NodeInterface $b): int {
    $a_order = is_numeric($a->get('field_taslak_madde_sira')->value) ? (float) $a->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
    $b_order = is_numeric($b->get('field_taslak_madde_sira')->value) ? (float) $b->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
    if ($a_order !== $b_order) {
      return $a_order <=> $b_order;
    }
    $number_order = strnatcasecmp((string) $a->get('field_taslak_madde_numarasi')->value, (string) $b->get('field_taslak_madde_numarasi')->value);
    return $number_order ?: ((int) $a->id() <=> (int) $b->id());
  }

  /** Returns the document heading without leaking an editorial record name. */
  private function childHeading(NodeInterface $child): string {
    $record_type = (string) ($child->get('field_taslak_kayit_turu')->value ?: 'madde');
    if (in_array($record_type, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE)
      && $child->hasField('field_taslak_bolum_basligi')
      && !$child->get('field_taslak_bolum_basligi')->isEmpty()) {
      return (string) $child->get('field_taslak_bolum_basligi')->value;
    }
    if ($child->hasField('field_taslak_madde_basligi') && !$child->get('field_taslak_madde_basligi')->isEmpty()) {
      return (string) $child->get('field_taslak_madde_basligi')->value;
    }
    return '';
  }

  /** Returns only an official heading; never falls back to the work record name. */
  private function childDocumentHeading(NodeInterface $child): string {
    $record_type = (string) ($child->get('field_taslak_kayit_turu')->value ?: 'madde');
    if (in_array($record_type, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE)
      && $child->hasField('field_taslak_bolum_basligi')
      && !$child->get('field_taslak_bolum_basligi')->isEmpty()) {
      return (string) $child->get('field_taslak_bolum_basligi')->value;
    }
    if ($child->hasField('field_taslak_madde_basligi') && !$child->get('field_taslak_madde_basligi')->isEmpty()) {
      return (string) $child->get('field_taslak_madde_basligi')->value;
    }
    return '';
  }

  /** Resolves the draft-wide heading alignment preference. */
  private function headingAlignment(NodeInterface $draft, string $record_type): string {
    $mode = $draft->hasField('field_taslak_baslik_hizalama')
      ? (string) $draft->get('field_taslak_baslik_hizalama')->value
      : 'varsayilan';
    if ($mode === 'sol') {
      return 'left';
    }
    if ($mode === 'orta') {
      return 'center';
    }
    return in_array($record_type, ['kitap', 'kisim', 'bolum', 'alt_baslik'], TRUE) ? 'center' : 'left';
  }

  /** Returns a semantic export heading level for hierarchy records. */
  private function exportHeadingTag(string $record_type): string {
    return match ($record_type) {
      'kitap' => 'h2',
      'kisim' => 'h3',
      'bolum' => 'h3',
      'alt_baslik' => 'h4',
      'madde_basligi' => 'h4',
      default => 'h2',
    };
  }

  /** Keeps exported markup within the subset supported by DOCX converters. */
  private function wordSafeHtml(string $html): string {
    // Drupal's XSS filter intentionally removes style attributes. Preserve a
    // small, explicitly allow-listed subset through a temporary data
    // attribute so PhpWord can apply paragraph alignment and text styling.
    $html = preg_replace_callback('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', function (array $match): string {
      $style = $this->safeWordStyle($match[1] ?? $match[2] ?? '');
      return $style !== '' ? ' data-ayb-word-style="' . Html::escape($style) . '"' : '';
    }, $html) ?: '';
    $filtered = Xss::filter($html, ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ol', 'ul', 'li', 'h3', 'h4', 'h5', 'blockquote', 'a', 'span']);
    return preg_replace_callback('/\sdata-ayb-word-style="([^"]*)"/i', static function (array $match): string {
      $style = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      return ' style="' . Html::escape($style) . '"';
    }, $filtered) ?: '';
  }

  /** Filters inline styles to values understood by PhpWord. */
  private function safeWordStyle(string $style): string {
    $allowed = [];
    foreach (explode(';', $style) as $declaration) {
      [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
      $property = strtolower(trim($property));
      $value = trim($value);
      if ($property === 'text-align' && preg_match('/^(left|right|center|justify)$/i', $value)) {
        $allowed[$property] = strtolower($value);
      }
      elseif (in_array($property, ['font-weight', 'font-style', 'text-decoration'], TRUE)
        && preg_match('/^[a-z0-9 -]+$/i', $value)
        && !preg_match('/url|expression|javascript/i', $value)) {
        $allowed[$property] = strtolower($value);
      }
      elseif (in_array($property, ['color', 'background-color'], TRUE)
        && preg_match('/^(#[0-9a-f]{3,8}|rgb\(\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*\))$/i', $value)) {
        $allowed[$property] = $value;
      }
      elseif ($property === 'line-height' && preg_match('/^(normal|[0-9.]+(?:%|pt|px)?)$/i', $value)) {
        $allowed[$property] = strtolower($value);
      }
    }
    $result = [];
    foreach ($allowed as $property => $value) {
      $result[] = $property . ': ' . $value;
    }
    return implode('; ', $result);
  }

  /** Builds the Word-like paper view for related article records. */
  private function articlePreview(NodeInterface $draft, array $children = []): array {
    $paper = $this->paper($draft->label());
    $paper['subtitle'] = ['#type' => 'html_tag', '#tag' => 'div', '#weight' => -1, '#attributes' => ['class' => ['ayb-draft-preview-paper__subtitle']], '#value' => $this->t('Madde bazlı taslak metni')];
    if (!$children) {
      $paper['#body'] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-preview-empty']], '#value' => $this->t('Bu taslağa bağlı madde kaydı bulunmuyor.')];
      return $paper;
    }
    $paper['toc'] = $this->tableOfContents($draft, $children);
    foreach ($children as $child) {
      if (!$child->access('view')) {
        continue;
      }
      $record_type = (string) ($child->get('field_taslak_kayit_turu')->value ?: 'madde');
      $number = (string) $child->get('field_taslak_madde_numarasi')->value;
      $heading = $this->childDocumentHeading($child);
      $alignment = $this->headingAlignment($draft, $record_type);
      $card = ['#type' => 'container', '#attributes' => ['id' => 'ayb-draft-preview-article-' . $child->id(), 'class' => ['ayb-draft-preview-article', 'ayb-draft-preview-article--' . preg_replace('/[^a-z0-9_-]+/i', '-', $record_type), 'ayb-draft-preview-article--align-' . $alignment]]];
      $number_label = ayb_mevzuat_calisma_article_number_label($draft, $number);
      if ($number_label !== '') {
        $card['number'] = ['#type' => 'html_tag', '#tag' => 'div', '#weight' => -1, '#attributes' => ['class' => ['ayb-draft-preview-article__number']], '#value' => $number_label];
      }
      if ($heading !== '') {
        $card['heading'] = ['#type' => 'html_tag', '#tag' => 'h3', '#weight' => -2, '#value' => $heading];
      }
      foreach ([
        'field_taslak_madde_mevcut_metin' => $this->t('Mevcut metin'),
        'field_taslak_madde_oneri_metin' => $this->t('Önerilen metin'),
        'field_taslak_madde_nihai_metin' => $this->t('Nihai metin'),
        'field_taslak_madde_gerekcesi' => $this->t('Madde gerekçesi'),
      ] as $field => $label) {
        if ($child->hasField($field) && !$child->get($field)->isEmpty() && $child->get($field)->access('view')) {
          $card[$field] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-field']], 'label' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $label], 'value' => $child->get($field)->view(['label' => 'hidden'])];
        }
      }
      $paper['#body'][] = $card;
    }
    if (count($paper['#body']) === 0) {
      $paper['#body']['empty'] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-preview-empty']], '#value' => $this->t('Görüntülenebilir madde kaydı bulunmuyor.')];
    }
    return $paper;
  }

  /** Builds a compact, linked table of contents for article previews. */
  private function tableOfContents(NodeInterface $draft, array $children): array {
    $items = [];
    foreach ($children as $child) {
      if (!$child->access('view')) {
        continue;
      }
      $record_type = (string) ($child->get('field_taslak_kayit_turu')->value ?: 'madde');
      $heading = $this->childDocumentHeading($child) ?: $child->label();
      $number = (string) $child->get('field_taslak_madde_numarasi')->value;
      $number_label = ayb_mevzuat_calisma_article_number_label($draft, $number);
      $label = $number_label !== '' ? $number_label . ' · ' . $heading : $heading;
      $level = match ($record_type) {
        'kitap' => 0,
        'kisim' => 1,
        'bolum' => 2,
        'alt_baslik' => 3,
        default => 4,
      };
      $items[] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => \Drupal\Core\Url::fromRoute('<current>', [], ['fragment' => 'ayb-draft-preview-article-' . $child->id()]),
        '#attributes' => ['class' => ['ayb-draft-preview-toc__link', 'ayb-draft-preview-toc__link--level-' . $level]],
      ];
    }
    return [
      '#type' => 'details',
      '#title' => $this->t('İçindekiler'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['ayb-draft-preview-toc']],
      'links' => ['#theme' => 'item_list', '#items' => $items, '#attributes' => ['class' => ['ayb-draft-preview-toc__list']]],
    ];
  }

  /** Builds the Word-like paper view for the parent text and legal context. */
  private function generalPreview(NodeInterface $draft, array $opinions = []): array {
    $paper = $this->paper($draft->label());
    $paper['subtitle'] = ['#type' => 'html_tag', '#tag' => 'div', '#weight' => -1, '#attributes' => ['class' => ['ayb-draft-preview-paper__subtitle']], '#value' => $this->t('Genel metin ve hukukî çerçeve')];
    $fields = [
      'field_taslak_mevcut_metin' => $this->t('Mevcut metin'),
      'field_taslak_metin' => $this->t('Taslak metni'),
      'field_taslak_nihai_metin' => $this->t('Nihai metin'),
      'field_taslak_amac_kapsam' => $this->t('Amaç ve kapsam'),
      'field_taslak_genel_gerekce' => $this->t('Genel gerekçe'),
      'field_taslak_hukuki_dayanak' => $this->t('Hukukî dayanak'),
      'field_taslak_nihai_gerekce' => $this->t('Nihai gerekçe'),
      'field_taslak_karsilastirma_notu' => $this->t('Karşılaştırma notu'),
    ];
    foreach ($fields as $field => $label) {
      if ($draft->hasField($field) && !$draft->get($field)->isEmpty() && $draft->get($field)->access('view')) {
        $paper['#body'][$field] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-field']], 'label' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $label], 'value' => $draft->get($field)->view(['label' => 'hidden'])];
      }
    }
    if ($opinions) {
      $paper['opinions_heading'] = ['#type' => 'html_tag', '#tag' => 'h3', '#attributes' => ['class' => ['ayb-draft-preview-subtitle']], '#value' => $this->t('Görüşler ve değerlendirmeler')];
      foreach ($opinions as $opinion) {
        if (!$opinion->access('view')) {
          continue;
        }
        $opinion_card = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-opinion']], 'title' => ['#type' => 'html_tag', '#tag' => 'h4', '#value' => $opinion->label()]];
        foreach (['field_taslak_gorus_metni' => $this->t('Görüş'), 'field_taslak_gorus_onerisi' => $this->t('Öneri'), 'field_taslak_gorus_cevabi' => $this->t('İdarenin cevabı')] as $field => $label) {
          if ($opinion->hasField($field) && !$opinion->get($field)->isEmpty() && $opinion->get($field)->access('view')) {
            $opinion_card[$field] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-field']], 'label' => ['#type' => 'html_tag', '#tag' => 'h5', '#value' => $label], 'value' => $opinion->get($field)->view(['label' => 'hidden'])];
          }
        }
        $paper['opinions'][] = $opinion_card;
      }
    }
    if (count($paper['#body'] ?? []) === 0) {
      $paper['#body']['empty'] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-preview-empty']], '#value' => $this->t('Görüntülenecek metin veya gerekçe bulunmuyor.')];
    }
    return $paper;
  }

  /** Builds a minimal document flow resembling a clean Word page. */
  private function cleanWordPreview(NodeInterface $draft, string $model, array $children = []): array {
    $paper = $this->paper($draft->label());
    $paper['#attributes']['class'][] = 'ayb-draft-preview-paper--clean';
    $paper['subtitle'] = ['#type' => 'html_tag', '#tag' => 'div', '#weight' => -1, '#attributes' => ['class' => ['ayb-draft-preview-paper__subtitle']], '#value' => $this->t('Taslak çalışma metni')];

    if ($model === 'madde_bazli') {
      foreach ($children as $child) {
        if (!$child->access('view')) {
          continue;
        }
        $record_type = (string) ($child->get('field_taslak_kayit_turu')->value ?: 'madde');
        $number = (string) $child->get('field_taslak_madde_numarasi')->value;
        $heading = $this->childDocumentHeading($child);
        $alignment = $this->headingAlignment($draft, $record_type);
        $article = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-clean-article', 'ayb-draft-preview-clean-article--' . preg_replace('/[^a-z0-9_-]+/i', '-', $record_type), 'ayb-draft-preview-clean-article--align-' . $alignment]]];
        $number_label = ayb_mevzuat_calisma_article_number_label($draft, $number);
        if ($number_label !== '') {
          $article['number'] = ['#type' => 'html_tag', '#tag' => 'div', '#weight' => -1, '#attributes' => ['class' => ['ayb-draft-preview-clean-article__number']], '#value' => $number_label];
        }
        if ($heading !== '') {
          $article['heading'] = ['#type' => 'html_tag', '#tag' => 'h3', '#weight' => -2, '#value' => $heading];
        }
        $selected_field = NULL;
        foreach (['field_taslak_madde_nihai_metin', 'field_taslak_madde_oneri_metin', 'field_taslak_madde_mevcut_metin'] as $field) {
          if ($child->hasField($field) && !$child->get($field)->isEmpty() && $child->get($field)->access('view')) {
            $selected_field = $field;
            break;
          }
        }
        if ($selected_field) {
          $article['text'] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-clean-article__text']], 'value' => $child->get($selected_field)->view(['label' => 'hidden'])];
        }
        $paper['#body'][] = $article;
      }
    }
    else {
      $selected_field = NULL;
      foreach (['field_taslak_nihai_metin', 'field_taslak_metin', 'field_taslak_mevcut_metin'] as $field) {
        if ($draft->hasField($field) && !$draft->get($field)->isEmpty() && $draft->get($field)->access('view')) {
          $selected_field = $field;
          break;
        }
      }
      if ($selected_field) {
        $paper['#body']['text'] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-clean-text']], 'value' => $draft->get($selected_field)->view(['label' => 'hidden'])];
      }
      foreach ([
        'field_taslak_genel_gerekce' => $this->t('Genel gerekçe'),
        'field_taslak_hukuki_dayanak' => $this->t('Hukukî dayanak'),
      ] as $field => $heading) {
        if ($draft->hasField($field) && !$draft->get($field)->isEmpty() && $draft->get($field)->access('view')) {
          $paper['#body'][$field] = ['#type' => 'container', '#attributes' => ['class' => ['ayb-draft-preview-clean-context']], 'heading' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $heading], 'value' => $draft->get($field)->view(['label' => 'hidden'])];
        }
      }
    }
    if (count($paper['#body']) === 0) {
      $paper['#body']['empty'] = ['#type' => 'html_tag', '#tag' => 'p', '#attributes' => ['class' => ['ayb-draft-preview-empty']], '#value' => $this->t('Temiz görünüm için kullanılabilir metin bulunmuyor.')];
    }
    return $paper;
  }

  /** Starts a document-like paper render array. */
  private function paper(string $title): array {
    return ['#theme' => 'ayb_mevzuat_calisma_draft_paper', '#attributes' => ['class' => ['ayb-draft-preview-paper']], '#title' => ['#type' => 'html_tag', '#tag' => 'div', '#attributes' => ['class' => ['ayb-draft-preview-paper__title']], '#value' => $title], '#body' => []];
  }

}
