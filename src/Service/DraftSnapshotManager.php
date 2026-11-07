<?php

namespace Drupal\ayb_mevzuat_calisma\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/** Creates immutable snapshots of a draft and its complete article tree. */
final class DraftSnapshotManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /** Captures the current draft state without changing moderation or publish state. */
  public function create(NodeInterface $draft, string $label, string $summary, string $visibility = 'editorial'): int {
    if ($draft->bundle() !== 'mevzuat_taslagi' || !$draft->access('view')) {
      throw new \InvalidArgumentException('Yalnızca erişilebilir mevzuat taslağı snapshot alınabilir.');
    }
    if (!in_array($visibility, ['editorial', 'public'], TRUE)) {
      throw new \InvalidArgumentException('Geçersiz snapshot görünürlüğü.');
    }
    if ($visibility === 'public' && (!$draft->isPublished() || !$this->currentUser->hasPermission('publish ayb draft snapshots'))) {
      throw new \RuntimeException('Kamu sürümü oluşturmak için yayımlanmış taslak ve yayın yetkisi gerekir.');
    }
    $payload = [
      'schema' => 1,
      'draft' => ['nid' => (int) $draft->id(), 'vid' => (int) $draft->getRevisionId(), 'title' => $draft->label(), 'changed' => (int) $draft->getChangedTime()],
      'created' => \Drupal::time()->getRequestTime(),
      'workflow' => $draft->hasField('moderation_state') ? (string) $draft->get('moderation_state')->value : NULL,
      'fields' => $this->fieldValues($draft),
      'articles' => [],
    ];
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'mevzuat_taslak_maddesi')->condition('field_taslak_madde_taslak', $draft->id())
      ->sort('field_taslak_madde_sira')->sort('nid')->execute();
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $article) {
      $payload['articles'][] = [
        'nid' => (int) $article->id(), 'vid' => (int) $article->getRevisionId(),
        'parent' => $article->hasField('field_taslak_ust_kayit') ? (int) ($article->get('field_taslak_ust_kayit')->target_id ?? 0) : 0,
        'weight' => $article->hasField('field_taslak_madde_sira') ? (float) $article->get('field_taslak_madde_sira')->value : 0,
        'workflow' => $article->hasField('moderation_state') ? (string) $article->get('moderation_state')->value : NULL,
        'fields' => $this->fieldValues($article),
      ];
    }
    $parent_map = array_column($payload['articles'], 'parent', 'nid');
    foreach ($payload['articles'] as &$entry) {
      $depth = 0;
      $parent = $entry['parent'];
      $seen = [(int) $entry['nid'] => TRUE];
      while ($parent && isset($parent_map[$parent]) && !isset($seen[$parent]) && $depth < 12) {
        $seen[$parent] = TRUE;
        $depth++;
        $parent = (int) $parent_map[$parent];
      }
      $entry['depth'] = $depth;
    }
    unset($entry);
    return (int) $this->database->insert('ayb_draft_snapshot')->fields([
      'draft_nid' => $draft->id(), 'uid' => $this->currentUser->id(), 'created' => $payload['created'],
      'visibility' => $visibility, 'state' => 'active', 'label' => mb_substr(trim($label), 0, 255),
      'summary' => $summary, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ])->execute();
  }

  /** Returns snapshots visible to the current account. */
  public function list(NodeInterface $draft): array {
    $query = $this->database->select('ayb_draft_snapshot', 's')->fields('s')->condition('draft_nid', $draft->id())->condition('state', 'active')->orderBy('created', 'DESC');
    if (!$this->currentUser->hasPermission('access ayb drafting workspace')) {
      $query->condition('visibility', 'public');
    }
    return $query->execute()->fetchAll();
  }

  private function fieldValues(NodeInterface $node): array {
    $values = [];
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if (str_starts_with($name, 'field_taslak_') && !$node->get($name)->isEmpty()) {
        $values[$name] = $node->get($name)->getValue();
      }
    }
    $values['title'] = $node->label();
    return $values;
  }
}
