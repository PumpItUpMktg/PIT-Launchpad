<?php

use App\Enums\UserRole;
use App\Enums\WizardStep;
use App\Models\Account;
use App\Models\OnboardingState;
use App\Models\Site;
use App\Onboarding\OnboardingWizard;

test('progress persists and resumes mid-flow', function () {
    $site = Site::factory()->for(Account::factory())->create();
    $wizard = app(OnboardingWizard::class);

    $wizard->completeStep($site, WizardStep::Account, UserRole::Operator);
    $wizard->completeStep($site, WizardStep::Identity, UserRole::Operator);
    $wizard->completeStep($site, WizardStep::ServiceCatalog, UserRole::Operator);

    // Persisted: current step advances to the next incomplete one.
    $state = OnboardingState::where('site_id', $site->id)->sole();
    expect($state->current_step)->toBe(WizardStep::Markets)
        ->and($state->isComplete(WizardStep::ServiceCatalog))->toBeTrue()
        ->and($state->isComplete(WizardStep::Markets))->toBeFalse();

    // Resuming returns the same state — no progress lost.
    $resumed = $wizard->stateFor($site->fresh());
    expect($resumed->id)->toBe($state->id)
        ->and($resumed->current_step)->toBe(WizardStep::Markets)
        ->and($resumed->completed_steps)->toContain('account');
});
