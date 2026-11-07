<?php

namespace Drupal\ayb_mevzuat_calisma\Plugin\Validation\Constraint;

use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class AybDraftConsistencyConstraintValidator extends ConstraintValidator {
  public function validate($node, Constraint $constraint): void {
    if (!$node instanceof NodeInterface) {
      return;
    }
    $error = function (string $field, string $message): void {
      $this->context->buildViolation($message)->atPath($field)->addViolation();
    };
    if ($node->bundle() === 'mevzuat_taslagi') {
      if ($node->hasField('field_taslak_calisma_modeli') && isset($node->original)
        && $node->original instanceof NodeInterface && $node->original->hasField('field_taslak_calisma_modeli')) {
        $old_model = $node->original->get('field_taslak_calisma_modeli')->value ?: 'tam_metin';
        $new_model = $node->get('field_taslak_calisma_modeli')->value ?: 'tam_metin';
        if ($old_model !== $new_model) {
          $has_text = ($node->original->hasField('field_taslak_metin') && !$node->original->get('field_taslak_metin')->isEmpty())
            || ($node->original->hasField('field_taslak_mevcut_metin') && !$node->original->get('field_taslak_mevcut_metin')->isEmpty());
          $has_articles = $node->id() && (bool) \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('type', 'mevzuat_taslak_maddesi')
            ->condition('field_taslak_madde_taslak', $node->id())
            ->range(0, 1)
            ->execute();
          if ($has_text || $has_articles) {
            $error('field_taslak_calisma_modeli', 'Çalışma modeli içerik girildikten sonra değiştirilemez. Model dönüşümünü yönetici kontrollü bir işlem olarak yapmalıdır.');
          }
        }
      }
      if ($node->get('field_taslak_senaryosu')->value === 'degisiklik' && $node->get('field_taslak_mevzuat')->isEmpty()) {
        $error('field_taslak_mevzuat', 'Mevzuat değişikliği için ilgili mevcut mevzuatı seçin.');
      }
      $start = $node->get('field_taslak_gorus_baslangic')->value;
      $end = $node->get('field_taslak_gorus_bitis')->value;
      if ($start && $end && $start > $end) {
        $error('field_taslak_gorus_bitis', 'Görüş bitişi başlangıç tarihinden önce olamaz.');
      }
      if ($node->id() && (string) $node->get('field_taslak_onceki')->target_id === (string) $node->id()) {
        $error('field_taslak_onceki', 'Taslak kendisinin önceki çalışması olamaz.');
      }
      if (in_array($node->get('moderation_state')->value, ['onaylandi', 'published'], TRUE)) {
        $article_model = $node->hasField('field_taslak_calisma_modeli') && $node->get('field_taslak_calisma_modeli')->value === 'madde_bazli';
        if (!$article_model && $node->get('field_taslak_nihai_metin')->isEmpty()) {
          $error('field_taslak_nihai_metin', 'Onay için nihai taslak metnini doldurun.');
        }
        if ($article_model) {
          $article_ids = \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('type', 'mevzuat_taslak_maddesi')
            ->condition('field_taslak_madde_taslak', $node->id())
            ->condition('field_taslak_kayit_turu', 'madde')
            ->execute();
          $missing_final = [];
          $not_approved = [];
          foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($article_ids) as $article) {
            if ($article->get('field_taslak_madde_deg_turu')->value !== 'mulga'
              && $article->get('field_taslak_madde_nihai_metin')->isEmpty()) {
              $missing_final[] = $article->label();
            }
            $article_state = (string) ($article->get('moderation_state')->value ?: 'draft');
            if (!in_array($article_state, ['published', 'arsiv'], TRUE)) {
              $not_approved[] = $article->label();
            }
          }
          if (!$article_ids) {
            $error('field_taslak_calisma_modeli', 'Madde bazlı taslağı onaylamadan önce en az bir madde kaydı oluşturun.');
          }
          elseif ($missing_final) {
            $error('field_taslak_calisma_modeli', 'Onaydan önce tüm yürürlükte kalacak maddelerin nihai metinlerini tamamlayın.');
          }
          elseif ($not_approved) {
            $error('field_taslak_calisma_modeli', 'Onaydan önce tüm maddeleri Yayında veya Arşiv durumuna taşıyın.');
          }
        }
        if ($node->get('field_taslak_onay_notu')->isEmpty()) {
          $error('field_taslak_onay_notu', 'Onay için karar gerekçesini doldurun.');
        }
      }
      $state = (string) $node->get('moderation_state')->value;
      if ($node->hasField('field_taslak_calisma_modeli')
        && $node->get('field_taslak_calisma_modeli')->value === 'madde_bazli'
        && in_array($state, ['incelemede', 'goruste', 'revize', 'onaylandi', 'published'], TRUE)) {
        $has_proposed_article = (bool) \Drupal::entityQuery('node')
          ->accessCheck(FALSE)
          ->condition('type', 'mevzuat_taslak_maddesi')
          ->condition('field_taslak_madde_taslak', $node->id())
          ->condition('field_taslak_kayit_turu', 'madde')
          ->range(0, 1)
          ->execute();
        if (!$has_proposed_article) {
          $error('field_taslak_calisma_modeli', 'Madde bazlı taslağı incelemeye göndermeden önce madde kayıtlarını oluşturun.');
        }
        if ($state === 'incelemede') {
          $article_ids = \Drupal::entityQuery('node')
            ->accessCheck(FALSE)
            ->condition('type', 'mevzuat_taslak_maddesi')
            ->condition('field_taslak_madde_taslak', $node->id())
            ->execute();
          $unreviewed = [];
          foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($article_ids) as $article) {
            if (in_array($article->get('field_taslak_kayit_turu')->value ?: 'madde', ['kitap', 'bolum', 'kisim', 'alt_baslik', 'madde_basligi'], TRUE)) {
              continue;
            }
            if (!in_array((string) ($article->get('moderation_state')->value ?: 'draft'), ['incelemede', 'published', 'arsiv'], TRUE)) {
              $unreviewed[] = $article->label();
            }
          }
          if ($unreviewed) {
            $error('field_taslak_calisma_modeli', 'Ana taslağı hukukî incelemeye göndermeden önce maddeleri de hukukî incelemeye gönderin.');
          }
        }
      }
    }
    if ($node->bundle() === 'mevzuat_taslak_maddesi') {
      $parent_draft = $node->get('field_taslak_madde_taslak')->entity;
      if (!$parent_draft) {
        $error('field_taslak_madde_taslak', 'Madde çalışmasını bir mevzuat taslağına bağlayın.');
      }
      elseif ($parent_draft->hasField('field_taslak_calisma_modeli')
        && $parent_draft->get('field_taslak_calisma_modeli')->value !== 'madde_bazli') {
        $error('field_taslak_madde_taslak', 'Madde kayıtları yalnızca “Madde bazlı” çalışma modelinde oluşturulabilir.');
      }
      elseif (!$parent_draft->access('update')) {
        $error('field_taslak_madde_taslak', 'Madde kaydı için üst taslağı güncelleme yetkiniz bulunmuyor.');
      }
      if ($node->hasField('field_taslak_ust_kayit') && !$node->get('field_taslak_ust_kayit')->isEmpty()) {
        $record_type = (string) ($node->get('field_taslak_kayit_turu')->value ?: 'madde');
        $upper = $node->get('field_taslak_ust_kayit')->entity;
        if (!$upper || $upper->bundle() !== 'mevzuat_taslak_maddesi') {
          $error('field_taslak_ust_kayit', 'Üst kayıt yalnızca başka bir taslak bölüm veya başlık kaydı olabilir.');
        }
        elseif ((int) $upper->id() === (int) $node->id()) {
          $error('field_taslak_ust_kayit', 'Bir kayıt kendisini üst kayıt olarak seçemez.');
        }
        elseif (!$parent_draft || (int) $upper->get('field_taslak_madde_taslak')->target_id !== (int) $parent_draft->id()) {
          $error('field_taslak_ust_kayit', 'Üst kayıt aynı mevzuat taslağına bağlı olmalıdır.');
        }
        else {
          $upper_type = (string) ($upper->get('field_taslak_kayit_turu')->value ?: 'madde');
          if (!in_array($upper_type, ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'], TRUE)) {
            $error('field_taslak_ust_kayit', 'Üst kayıt olarak yalnızca kitap, kısım, bölüm veya başlık türleri seçilebilir.');
          }
          $allowed_parents = match ($record_type) {
            'kisim' => ['kitap'],
            'bolum' => ['kitap', 'kisim'],
            'alt_baslik' => ['kitap', 'kisim', 'bolum'],
            'madde_basligi' => ['kitap', 'kisim', 'bolum', 'alt_baslik'],
            'madde' => ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'],
            default => [],
          };
          if ($record_type === 'kitap') {
            $error('field_taslak_ust_kayit', 'Kitap kayıtları taslağın kökünde yer almalıdır.');
          }
          elseif (!$allowed_parents || !in_array($upper_type, $allowed_parents, TRUE)) {
            $error('field_taslak_ust_kayit', 'Seçilen kayıt türü için uygun olmayan bir üst kayıt seçildi.');
          }
          $seen = [(int) $node->id() => TRUE];
          $cursor = $upper;
          for ($depth = 0; $depth < 100 && $cursor->hasField('field_taslak_ust_kayit') && !$cursor->get('field_taslak_ust_kayit')->isEmpty(); $depth++) {
            $cursor = $cursor->get('field_taslak_ust_kayit')->entity;
            if (!$cursor) {
              break;
            }
            if (isset($seen[(int) $cursor->id()])) {
              $error('field_taslak_ust_kayit', 'Üst kayıt ilişkisi döngü oluşturamaz.');
              break;
            }
            $seen[(int) $cursor->id()] = TRUE;
          }
        }
      }
      $record_type = $node->get('field_taslak_kayit_turu')->value ?: 'madde';
      if ($record_type === 'madde' && $node->get('field_taslak_madde_numarasi')->isEmpty()) {
        $error('field_taslak_madde_numarasi', 'Madde kaydı için madde numarasını belirtin.');
      }
      if ($record_type === 'madde_basligi' && $node->get('field_taslak_madde_basligi')->isEmpty()) {
        $error('field_taslak_madde_basligi', 'Madde başlığı kaydı için başlığı belirtin.');
      }
      if (in_array($record_type, ['kitap', 'bolum', 'kisim', 'alt_baslik'], TRUE) && $node->get('field_taslak_bolum_basligi')->isEmpty()) {
        $label = match ($record_type) {
          'kitap' => 'Kitap',
          'alt_baslik' => 'Alt başlık',
          'kisim' => 'Kısım başlığı',
          default => 'Bölüm başlığı',
        };
        $error('field_taslak_bolum_basligi', $label . ' seçildiğinde “Kitap / kısım / bölüm / alt başlık başlığı” alanını doldurun.');
      }
    }
    if ($node->bundle() === 'mevzuat_taslak_gorusu') {
      $parent_draft = $node->get('field_taslak_gorus_taslak')->entity;
      if (!$parent_draft) {
        $error('field_taslak_gorus_taslak', 'Görüş kaydını bir mevzuat taslağına bağlayın.');
      }
      elseif (!$parent_draft->access('update')) {
        $error('field_taslak_gorus_taslak', 'Görüş kaydı için üst taslağı güncelleme yetkiniz bulunmuyor.');
      }
      $article = $node->get('field_taslak_gorus_madde')->entity;
      if ($article && (string) $article->get('field_taslak_madde_taslak')->target_id !== (string) $node->get('field_taslak_gorus_taslak')->target_id) {
        $error('field_taslak_gorus_madde', 'Seçilen madde görüşün üst taslağına ait olmalıdır.');
      }
      if (in_array($node->get('field_taslak_gorus_sonucu')->value, ['kabul', 'kismen', 'reddedildi'], TRUE) && $node->get('field_taslak_gorus_cevabi')->isEmpty()) {
        $error('field_taslak_gorus_cevabi', 'Değerlendirme sonucunun gerekçesini idarenin cevabı alanında belirtin.');
      }
    }
  }
}
