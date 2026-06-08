<?php

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Site;
use App\Onboarding\IncompleteOnboardingException;
use App\Onboarding\IntakeCollector;
use App\Onboarding\OnboardingWizard;
use App\Onboarding\StepNotEditableException;

function completeIntake(Site $site): void
{
    $collector = app(IntakeCollector::class);

    $collector->saveIdentity($site, ['palette' => ['primary' => '#000']], ['name' => 'HQ']);
    $services = $collector->saveServiceCatalog($site, [['name' => 'Plumbing', 'silo_role' => 'pillar']]);
    $collector->saveMarkets($site, [['name' => 'Austin', 'geo_id' => '48453', 'tier' => 'priority']]);
    $collector->saveProof($site, [['type' => 'warranty', 'is_substantiated' => true, 'service_ids' => [$services->first()->id]]]);
    $collector->saveTargets($site, [
        'keywords' => ['water heater repair'],
        'conversion' => ['primary_actions' => ['call']],
    ]);
    $voice = $collector->synthesizeVoice($site, []);
    $collector->activateVoice($voice);
}

test('the completeness gate reports what is missing', function () {
    $site = Site::factory()->for(Account::factory())->create();
    $wizard = app(OnboardingWizard::class);

    $missing = $wizard->missing($site);

    expect($missing)->toContain('branding')
        ->and($missing)->toContain('service')
        ->and($missing)->toContain('priority_market')
        ->and($missing)->toContain('substantiated_proof')
        ->and($missing)->toContain('conversion_config')
        ->and($missing)->toContain('active_voice')
        ->and($missing)->toContain('keyword_anchor')
        ->and($wizard->canLaunch($site))->toBeFalse();
});

test('launch is blocked until the intake is complete, then succeeds', function () {
    $site = Site::factory()->for(Account::factory())->create();
    $wizard = app(OnboardingWizard::class);

    expect(fn () => $wizard->launch($site, UserRole::Operator))
        ->toThrow(IncompleteOnboardingException::class);

    completeIntake($site);

    expect($wizard->canLaunch($site))->toBeTrue();

    $state = $wizard->launch($site, UserRole::Operator);
    expect($state->launched_at)->not->toBeNull()
        ->and($site->fresh()->status)->toBe(SiteStatus::Active);
});

test('a client may not launch the tenant', function () {
    $site = Site::factory()->for(Account::factory())->create();
    completeIntake($site);

    expect(fn () => app(OnboardingWizard::class)->launch($site, UserRole::Client))
        ->toThrow(StepNotEditableException::class);
});
