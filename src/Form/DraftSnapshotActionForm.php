<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Publishes or withdraws a fixed snapshot without altering its payload. */
final class DraftSnapshotActionForm extends ConfirmFormBase {
  private object $snapshot;
  private string $action = 'publish';

  public function __construct(private readonly Connection $database, private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): self { return new self($container->get('database'), $container->get('entity_type.manager')); }

  public function getFormId(): string { return 'ayb_mevzuat_calisma_snapshot_action'; }

  public function buildForm(array $form, FormStateInterface $form_state, int $snapshot = 0, string $action = 'publish'): array {
    $this->action = $action;
    if (!$this->currentUser()->hasPermission('publish ayb draft snapshots')) { throw new AccessDeniedHttpException(); }
    $this->snapshot = $this->database->select('ayb_draft_snapshot', 's')->fields('s')->condition('snapshot_id', $snapshot)->execute()->fetchObject() ?: throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    if ($action === 'publish') {
      $draft = $this->entityTypeManager->getStorage('node')->load($this->snapshot->draft_nid);
      if (!$draft || !$draft->isPublished()) { throw new AccessDeniedHttpException(); }
    }
    $form['snapshot'] = ['#type' => 'value', '#value' => $snapshot];
    $form['action'] = ['#type' => 'value', '#value' => $action];
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string { return $this->action === 'publish' ? $this->t('Bu sabit sürüm kamuya açılsın mı?') : $this->t('Bu sürümün kamu görünürlüğü kaldırılsın mı?'); }
  public function getCancelUrl(): Url { return Url::fromRoute('ayb_mevzuat_calisma.snapshot_list', ['draft' => $this->snapshot->draft_nid]); }
  public function getConfirmText(): string { return $this->t('Onayla'); }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $snapshot = (int) $form_state->getValue('snapshot');
    $visibility = $form_state->getValue('action') === 'publish' ? 'public' : 'editorial';
    $this->database->update('ayb_draft_snapshot')->fields(['visibility' => $visibility])->condition('snapshot_id', $snapshot)->condition('state', 'active')->execute();
    $this->messenger()->addStatus($visibility === 'public' ? $this->t('Sabit sürüm kamuya açıldı.') : $this->t('Sürüm kamu görünümünden geri çekildi.'));
    $form_state->setRedirect('ayb_mevzuat_calisma.snapshot_list', ['draft' => $this->snapshot->draft_nid]);
  }
}
