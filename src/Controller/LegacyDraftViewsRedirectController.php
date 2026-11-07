<?php

namespace Drupal\ayb_mevzuat_calisma\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Redirects deprecated drafting Views to the editor workspace. */
final class LegacyDraftViewsRedirectController extends ControllerBase {

  public function go(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('ayb_mevzuat_calisma.draft_workspace')->toString(), 301);
  }

}
