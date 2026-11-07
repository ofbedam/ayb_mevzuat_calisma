<?php

namespace Drupal\ayb_mevzuat_calisma\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Controller\ControllerBase;

/** Opens a revision comparison workspace for a legislative draft. */
final class DraftRevisionComparisonController extends ControllerBase {

  /** Restricts comparisons to supported legislative working records. */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    $supported = in_array($node->bundle(), ['mevzuat_taslagi', 'mevzuat_taslak_maddesi', 'mevzuat_taslak_gorusu'], TRUE);
    if ($node->bundle() === 'mevzuat_taslagi') {
      $supported = $supported
        && $node->hasField('field_taslak_calisma_modeli')
        && $node->get('field_taslak_calisma_modeli')->value === 'tam_metin';
    }
    return AccessResult::allowedIf($supported)
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

  /** Builds the revision selector and comparison. */
  public function compare(NodeInterface $node): array {
    return $this->formBuilder()->getForm('Drupal\\ayb_mevzuat_calisma\\Form\\DraftRevisionComparisonForm', $node);
  }

}
