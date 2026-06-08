<?php

use App\Enums\UserRole;
use App\Enums\WizardStep;
use App\Models\Account;
use App\Models\Site;
use App\Onboarding\OnboardingWizard;
use App\Onboarding\RoleGate;
use App\Onboarding\StepNotEditableException;

test('the role gate enforces hybrid authorship', function () {
    $gate = new RoleGate;

    // Client may self-serve the factual buckets but not operator steps.
    expect($gate->canEdit(UserRole::Client, WizardStep::ServiceCatalog))->toBeTrue()
        ->and($gate->canEdit(UserRole::Client, WizardStep::Voice))->toBeFalse()
        ->and($gate->canEdit(UserRole::Client, WizardStep::Account))->toBeFalse()
        ->and($gate->canEdit(UserRole::Operator, WizardStep::Voice))->toBeTrue();

    expect($gate->editableSteps(UserRole::Client))->toHaveCount(6);
});

test('completing an operator step as a client is rejected', function () {
    $site = Site::factory()->for(Account::factory())->create();
    $wizard = app(OnboardingWizard::class);

    // Client can complete a factual step.
    $wizard->completeStep($site, WizardStep::Identity, UserRole::Client);
    expect($wizard->stateFor($site)->isComplete(WizardStep::Identity))->toBeTrue();

    // But not the voice step.
    expect(fn () => $wizard->completeStep($site, WizardStep::Voice, UserRole::Client))
        ->toThrow(StepNotEditableException::class);
});
