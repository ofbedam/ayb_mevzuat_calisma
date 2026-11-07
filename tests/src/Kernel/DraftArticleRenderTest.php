<?php

namespace DrupalTests\ayb_mevzuat_calisma\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Render\RenderContext;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies that article records render safely on a draft page.
 *
 */
#[Group('ayb_mevzuat_calisma')]
#[RunTestsInSeparateProcesses]
final class DraftArticleRenderTest extends KernelTestBase {

  /** {@inheritdoc} */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'workflows',
    'content_moderation',
  ];

  /** {@inheritdoc} */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'user', 'node', 'filter', 'content_moderation']);
    $anonymous = Role::load(RoleInterface::ANONYMOUS_ID);
    $anonymous?->grantPermission('access content')->save();
    NodeType::create(['type' => 'mevzuat_taslagi', 'name' => 'Mevzuat Taslağı'])->save();
    NodeType::create(['type' => 'mevzuat_taslak_maddesi', 'name' => 'Taslak Maddesi'])->save();
    $this->addField('mevzuat_taslagi', 'field_taslak_calisma_modeli', 'list_string', ['allowed_values' => ['madde_bazli' => 'Madde bazlı']]);
    $this->addField('mevzuat_taslagi', 'field_taslak_baslik_hizalama', 'list_string', ['allowed_values' => ['varsayilan' => 'Varsayılan', 'sol' => 'Sol', 'orta' => 'Orta']]);
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_madde_taslak', 'entity_reference', ['target_type' => 'node']);
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_kayit_turu', 'list_string', ['allowed_values' => ['kitap' => 'Kitap', 'kisim' => 'Kısım başlığı', 'madde' => 'Madde']]);
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_ust_kayit', 'entity_reference', ['target_type' => 'node']);
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_bolum_basligi', 'string');
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_madde_basligi', 'string');
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_madde_numarasi', 'string');
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_madde_sira', 'integer');
    $this->addField('mevzuat_taslak_maddesi', 'field_taslak_madde_oneri_metin', 'text_long');
    require_once dirname(__DIR__, 3) . '/includes/draft.inc';
  }

  /** Ensures the parent render path never calls hasField() on NULL. */
  public function testArticleTextRendersWithAlignment(): void {
    $draft = Node::create([
      'type' => 'mevzuat_taslagi',
      'title' => 'Test taslağı',
      'status' => 1,
      'field_taslak_calisma_modeli' => 'madde_bazli',
      'field_taslak_baslik_hizalama' => 'varsayilan',
    ]);
    $draft->save();
    $article = Node::create([
      'type' => 'mevzuat_taslak_maddesi',
      'title' => 'Çalışma kaydı adı',
      'status' => 1,
      'field_taslak_madde_taslak' => $draft,
      'field_taslak_kayit_turu' => 'kisim',
      'field_taslak_bolum_basligi' => 'BİRİNCİ KISIM',
      'field_taslak_madde_sira' => 1,
      'field_taslak_madde_oneri_metin' => ['value' => '<p>Test metni.</p>', 'format' => 'basic_html'],
    ]);
    $article->save();

    $build = ayb_mevzuat_calisma_draft_article_text($draft);
    $html = (string) $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn() => $this->container->get('renderer')->render($build));

    $this->assertStringContainsString('BİRİNCİ KISIM', $html);
    $this->assertStringContainsString('ayb-draft-article-card--align-center', $html);
    $this->assertStringNotContainsString('Call to a member function hasField() on null', $html);
  }

  /** Ensures the explicit left alignment preference reaches the render. */
  public function testExplicitLeftAlignmentRenders(): void {
    $draft = Node::create([
      'type' => 'mevzuat_taslagi', 'title' => 'Sol taslak', 'status' => 1,
      'field_taslak_calisma_modeli' => 'madde_bazli', 'field_taslak_baslik_hizalama' => 'sol',
    ]);
    $draft->save();
    $article = Node::create([
      'type' => 'mevzuat_taslak_maddesi', 'title' => 'Madde kaydı', 'status' => 1,
      'field_taslak_madde_taslak' => $draft, 'field_taslak_kayit_turu' => 'kisim',
      'field_taslak_bolum_basligi' => 'İKİNCİ KISIM', 'field_taslak_madde_sira' => 1,
    ]);
    $article->save();

    $build = ayb_mevzuat_calisma_draft_article_text($draft);
    $html = (string) $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn() => $this->container->get('renderer')->render($build));
    $this->assertStringContainsString('ayb-draft-article-card--align-left', $html);
  }

  /** Ensures a Kitap record is rendered before its child record. */
  public function testBookParentRendersBeforeChild(): void {
    $draft = Node::create([
      'type' => 'mevzuat_taslagi', 'title' => 'Kitap taslağı', 'status' => 1,
      'field_taslak_calisma_modeli' => 'madde_bazli', 'field_taslak_baslik_hizalama' => 'varsayilan',
    ]);
    $draft->save();
    $book = Node::create([
      'type' => 'mevzuat_taslak_maddesi', 'title' => 'Kitap kaydı', 'status' => 1,
      'field_taslak_madde_taslak' => $draft, 'field_taslak_kayit_turu' => 'kitap',
      'field_taslak_bolum_basligi' => 'BİRİNCİ KİTAP', 'field_taslak_madde_sira' => 10,
    ]);
    $book->save();
    $article = Node::create([
      'type' => 'mevzuat_taslak_maddesi', 'title' => 'Madde kaydı', 'status' => 1,
      'field_taslak_madde_taslak' => $draft, 'field_taslak_ust_kayit' => $book,
      'field_taslak_kayit_turu' => 'madde', 'field_taslak_madde_numarasi' => '1',
      'field_taslak_madde_sira' => 20,
    ]);
    $article->save();

    $build = ayb_mevzuat_calisma_draft_article_text($draft);
    $html = (string) $this->container->get('renderer')->executeInRenderContext(new RenderContext(), fn() => $this->container->get('renderer')->render($build));
    $this->assertStringContainsString('BİRİNCİ KİTAP', $html);
    $this->assertLessThan(strpos($html, 'MADDE 1-'), strpos($html, 'BİRİNCİ KİTAP'));
  }

  /** Creates a minimal field required by the render test. */
  private function addField(string $bundle, string $name, string $type, array $settings = []): void {
    $storage = FieldStorageConfig::loadByName('node', $name);
    if (!$storage) {
      $storage_settings = $type === 'entity_reference' ? ['target_type' => 'node'] : ($type === 'list_string' ? $settings : []);
      $storage = FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type, 'settings' => $storage_settings]);
      $storage->save();
    }
    if (!FieldConfig::loadByName('node', $bundle, $name)) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle, 'settings' => $type === 'entity_reference' ? ['handler' => 'default:node'] : []])->save();
    }
  }

}
