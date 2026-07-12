<?php

use App\Enums\SetupStep;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Plan;
use App\Filament\Pages\SetupHome;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PublishedContentResource;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('the Setup entry lands on the working site\'s current step', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    SetupState::query()->create(['site_id' => $site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    session(['guided_site_id' => $site->id]);

    // current_step 3 = Brand — Setup lands exactly where the operator left off.
    Livewire::test(SetupHome::class)->assertRedirect(Brand::getUrl());
})->skip(fn () => ! method_exists(SetupState::class, 'step'), 'step resolution required');

it('the setup rail is the five consolidated steps, in order', function () {
    // The setup-redesign contract: 7 steps became 5 — Territory folded into WhereYouWork,
    // Structure/Inventory/Approve folded into Plan (Approve is a button there, not a step).
    expect(SetupStep::setupSteps())->toBe([
        SetupStep::Business,
        SetupStep::ConnectWordpress,
        SetupStep::Brand,
        SetupStep::WhereYouWork,
        SetupStep::Plan,
    ])->and(SetupStep::Plan->eyebrow())->toBe('Step 5 of 5');
});

it('the unified menu shows one Setup entry — step pages hidden, Grow promoted to Work', function () {
    // The guided step pages leave the sidebar (the in-page rail is the step navigation)…
    expect(Business::shouldRegisterNavigation())->toBeFalse()
        ->and(Plan::shouldRegisterNavigation())->toBeFalse()
        // …Grow is the permanent workbench and leads the Work group…
        ->and(Grow::shouldRegisterNavigation())->toBeTrue()
        // …and the superseded surfaces are retired from the nav (routes kept).
        ->and(PageResource::shouldRegisterNavigation())->toBeFalse()
        ->and(PublishedContentResource::shouldRegisterNavigation())->toBeFalse();
});
