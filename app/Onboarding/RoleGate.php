<?php

namespace App\Onboarding;

use App\Enums\UserRole;
use App\Enums\WizardStep;

/**
 * Enforces hybrid authorship: operators may edit any step; clients are limited
 * to the factual buckets (steps 2–7).
 */
class RoleGate
{
    public function canEdit(UserRole $role, WizardStep $step): bool
    {
        return $role === UserRole::Operator || $step->isClientStep();
    }

    /**
     * @return list<WizardStep>
     */
    public function editableSteps(UserRole $role): array
    {
        return array_values(array_filter(WizardStep::ordered(), fn (WizardStep $s) => $this->canEdit($role, $s)));
    }
}
