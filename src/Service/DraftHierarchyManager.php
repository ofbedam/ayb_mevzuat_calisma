<?php

namespace Drupal\ayb_mevzuat_calisma\Service;

use Drupal\node\NodeInterface;

/** Provides one canonical implementation of the draft document hierarchy. */
final class DraftHierarchyManager {

  /** Groups records by their explicit parent and applies stable ordering. */
  public function build(NodeInterface $draft, array $records): array {
    $by_id = [];
    $children = [0 => []];
    foreach ($records as $record) {
      if ($record instanceof NodeInterface && $record->id()) {
        $by_id[(int) $record->id()] = $record;
      }
    }
    foreach ($by_id as $id => $record) {
      $parent_id = $record->hasField('field_taslak_ust_kayit') && !$record->get('field_taslak_ust_kayit')->isEmpty()
        ? (int) $record->get('field_taslak_ust_kayit')->target_id : 0;
      if (!$parent_id || !isset($by_id[$parent_id]) || $parent_id === $id) {
        $parent_id = 0;
      }
      $children[$parent_id][] = $record;
    }
    $sort = static function (NodeInterface $a, NodeInterface $b): int {
      $a_order = is_numeric($a->get('field_taslak_madde_sira')->value) ? (float) $a->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
      $b_order = is_numeric($b->get('field_taslak_madde_sira')->value) ? (float) $b->get('field_taslak_madde_sira')->value : PHP_FLOAT_MAX;
      return $a_order <=> $b_order ?: ((int) $a->id() <=> (int) $b->id());
    };
    foreach ($children as &$siblings) {
      usort($siblings, $sort);
    }
    unset($siblings);
    return ['records' => $by_id, 'children' => $children];
  }

  /** Flattens the hierarchy in document order while preventing cycles. */
  public function flatten(array $hierarchy, int $parent_id = 0, int $depth = 0, array &$visited = []): array {
    $result = [];
    $this->appendBranch($hierarchy, $parent_id, $depth, $visited, $result);
    return $result;
  }

  /** Appends records in document order without copying intermediate arrays. */
  private function appendBranch(array $hierarchy, int $parent_id, int $depth, array &$visited, array &$result): void {
    foreach ($hierarchy['children'][$parent_id] ?? [] as $record) {
      $id = (int) $record->id();
      if (isset($visited[$id])) {
        continue;
      }
      $visited[$id] = TRUE;
      $result[] = ['record' => $record, 'depth' => $depth];
      $this->appendBranch($hierarchy, $id, $depth + 1, $visited, $result);
    }
  }

}
