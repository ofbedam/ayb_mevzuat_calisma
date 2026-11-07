<?php

namespace Drupal\ayb_mevzuat_calisma\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;

/** Provides a short-lived editor lock for draft records. */
final class DraftLockManager {

  private const COLLECTION = 'ayb_mevzuat_calisma_locks';
  private const TTL = 1800;

  public function __construct(
    private KeyValueExpirableFactoryInterface $factory,
    private AccountProxyInterface $currentUser,
  ) {}

  /** Acquires or renews the current user's lock. */
  public function acquire(NodeInterface $entity): bool {
    if ($entity->isNew()) {
      return TRUE;
    }
    $store = $this->factory->get(self::COLLECTION);
    $key = $this->key($entity);
    $guard = 'ayb_draft_lock:' . $key;
    $backend = \Drupal::lock();
    if (!$backend->acquire($guard, 10.0)) {
      return FALSE;
    }
    try {
      $existing = $store->get($key);
      $uid = (int) $this->currentUser->id();
      if (is_array($existing) && (int) ($existing['uid'] ?? 0) !== $uid) {
        return FALSE;
      }
      $store->set($key, [
        'uid' => $uid,
        'name' => (string) $this->currentUser->getDisplayName(),
        'updated' => \Drupal::time()->getRequestTime(),
      ], self::TTL);
      return TRUE;
    }
    finally {
      $backend->release($guard);
    }
  }

  /** Returns whether the record is locked by another user. */
  public function isLockedByOther(NodeInterface $entity): bool {
    $lock = $this->factory->get(self::COLLECTION)->get($this->key($entity));
    return is_array($lock) && (int) ($lock['uid'] ?? 0) !== (int) $this->currentUser->id();
  }

  /** Returns whether the current user owns the lock or the record is unlocked. */
  public function owns(NodeInterface $entity): bool {
    return !$this->isLockedByOther($entity);
  }

  /** Releases the current user's lock after a successful save. */
  public function release(NodeInterface $entity): void {
    if ($entity->isNew()) {
      return;
    }
    $store = $this->factory->get(self::COLLECTION);
    $key = $this->key($entity);
    $lock = $store->get($key);
    if (is_array($lock) && (int) ($lock['uid'] ?? 0) === (int) $this->currentUser->id()) {
      $store->delete($key);
    }
  }

  /** Returns the display name of another lock owner, when available. */
  public function ownerName(NodeInterface $entity): string {
    $lock = $this->factory->get(self::COLLECTION)->get($this->key($entity));
    return is_array($lock) ? (string) ($lock['name'] ?? '') : '';
  }

  private function key(NodeInterface $entity): string {
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

}
