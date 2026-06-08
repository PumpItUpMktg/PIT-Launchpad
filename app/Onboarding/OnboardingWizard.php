<?php

namespace App\Onboarding;

use App\Enums\UserRole;
use App\Enums\WizardStep;
use App\Models\OnboardingState;
use App\Models\Site;

/**
 * Orchestrates the resumable, role-gated onboarding flow over a Site: tracks
 * progress, enforces hybrid authorship, and gates launch on completeness.
 */
class OnboardingWizard
{
    public function __construct(
        private readonly RoleGate $gate,
        private readonly CompletenessChecker $completeness,
    ) {}

    public function stateFor(Site $site): OnboardingState
    {
        return OnboardingState::firstOrCreate(
            ['site_id' => $site->id],
            ['current_step' => WizardStep::Account->value, 'completed_steps' => []],
        );
    }

    public function completeStep(Site $site, WizardStep $step, UserRole $role): OnboardingState
    {
        if (! $this->gate->canEdit($role, $step)) {
            throw new StepNotEditableException($role, $step);
        }

        $state = $this->stateFor($site);
        $state->markComplete($step);

        return $state;
    }

    /**
     * @return list<string>
     */
    public function missing(Site $site): array
    {
        return $this->completeness->missing($site);
    }

    public function canLaunch(Site $site): bool
    {
        return $this->completeness->isComplete($site);
    }

    public function launch(Site $site, UserRole $role): OnboardingState
    {
        if ($role !== UserRole::Operator) {
            throw new StepNotEditableException($role, WizardStep::Launch);
        }

        $missing = $this->completeness->missing($site);
        if ($missing !== []) {
            throw new IncompleteOnboardingException($missing);
        }

        $state = $this->stateFor($site);
        $state->markComplete(WizardStep::Launch);
        $state->update(['launched_at' => now()]);
        $site->update(['status' => 'active']);

        return $state;
    }
}
