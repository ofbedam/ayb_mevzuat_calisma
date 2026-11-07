<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Creates an editorial snapshot without changing moderation states. */
final class DraftSnapshotForm extends FormBase {

  public function __construct(
    private readonly \Drupal\ayb_mevzuat_calisma\Service\DraftSnapshotManager $snapshotManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('ayb_mevzuat_calisma.snapshot'), $container->get('entity_type.manager'));
  }

  public function getFormId(): string { return 'ayb_mevzuat_calisma_draft_snapshot_form'; }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $draft = NULL): array {
    $form['label'] = ['#type' => 'textfield', '#title' => $this->t('Sürüm adı'), '#required' => TRUE, '#maxlength' => 255, '#default_value' => $this->t('Editoryal çalışma noktası')];
    $form['summary'] = ['#type' => 'textarea', '#title' => $this->t('Değişiklik özeti'), '#description' => $this->t('Bu çalışma noktasını neden sakladığınızı belirtin.'), '#rows' => 3];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Editoryal sürümü sabitle'), '#button_type' => 'primary'];
    $form['draft_id'] = ['#type' => 'value', '#value' => $draft?->id()];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $draft = $this->entityTypeManager->getStorage('node')->load($form_state->getValue('draft_id'));
    if (!$draft instanceof NodeInterface || !$draft->access('update')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $id = $this->snapshotManager->create($draft, $form_state->getValue('label'), $form_state->getValue('summary') ?? '', 'editorial');
    $this->messenger()->addStatus($this->t('Editoryal sürüm #@id sabitlendi. Madde moderasyon durumları değiştirilmedi.', ['@id' => $id]));
    $form_state->setRedirect('ayb_mevzuat_calisma.snapshot_list', ['draft' => $draft->id()]);
  }
}
