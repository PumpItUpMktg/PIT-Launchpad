<?php

namespace App\Onboarding;

use App\Enums\UserRole;
use App\Enums\WizardStep;
use RuntimeException;

/**
 * Thrown when a role attempts a step it may not edit (hybrid authorship).
 */
class StepNotEditableException extends RuntimeException
{
    public function __construct(public readonly UserRole $role, public readonly WizardStep $step)
    {
        parent::__construct("Role [{$role->value}] may not edit step [{$step->value}].");
    }
}
