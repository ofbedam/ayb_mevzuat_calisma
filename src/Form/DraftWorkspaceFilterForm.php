<?php

namespace Drupal\ayb_mevzuat_calisma\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Provides simple GET filters for the drafting workspace. */
final class DraftWorkspaceFilterForm extends FormBase {

  public function __construct(protected $entityTypeManager) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'ayb_draft_workspace_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $workflow = $this->entityTypeManager->getStorage('workflow')->load('ayb_taslak_editorial');
    $states = [];
    if ($workflow) {
      foreach ($workflow->getTypePlugin()->getStates() as $id => $state) {
        $states[$id] = $state->label();
      }
    }
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('ayb_mevzuat_calisma.draft_workspace')->toString();
    $form['#attributes']['class'][] = 'ayb-draft-workspace-filters';
    $form['#attributes']['class'][] = 'ayb-draft-workspace-filters__fields';
    $form['q'] = [
      '#type' => 'search',
      '#title' => $this->t('Taslakta ara'),
      '#title_display' => 'invisible',
      '#placeholder' => $this->t('Başlıkta ara'),
      '#default_value' => is_scalar($request->query->get('q', '')) ? $request->query->get('q', '') : '',
      '#size' => 28,
    ];
    $form['stage'] = [
      '#type' => 'select',
      '#title' => $this->t('Aşama'),
      '#options' => ['' => $this->t('Tüm aşamalar')] + $states,
      '#default_value' => is_scalar($request->query->get('stage', '')) ? $request->query->get('stage', '') : '',
    ];
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Çalışma modeli'),
      '#options' => ['' => $this->t('Tüm modeller'), 'tam_metin' => $this->t('Tam metin'), 'madde_bazli' => $this->t('Madde bazlı')],
      '#default_value' => is_scalar($request->query->get('model', '')) ? $request->query->get('model', '') : '',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['apply'] = ['#type' => 'submit', '#value' => $this->t('Filtrele')];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Temizle'),
      '#url' => Url::fromRoute('ayb_mevzuat_calisma.draft_workspace'),
      '#attributes' => ['class' => ['button', 'button--small']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // GET submission places the selected filters in the query string.
  }

}
