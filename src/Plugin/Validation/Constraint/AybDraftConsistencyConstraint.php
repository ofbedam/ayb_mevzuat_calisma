<?php

namespace Drupal\ayb_mevzuat_calisma\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(id: 'AybDraftConsistency', label: new TranslatableMarkup('AYB taslak ilişki ve süreç tutarlılığı'), type: 'entity:node')]
class AybDraftConsistencyConstraint extends SymfonyConstraint {}
