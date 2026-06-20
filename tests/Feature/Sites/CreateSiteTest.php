<?php

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Business;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('offers a New site create action on the portfolio', function () {
    Livewire::test(ListSites::class)->assertActionExists('create');
});

it('creates a site in Onboarding status via the lightweight form', function () {
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm([
            'account_id' => $account->id,
            'brand_name' => 'Eric Test Plumbing',
            'domain_url' => 'https://eric-test.com',
            'status' => SiteStatus::Onboarding->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->where('brand_name', 'Eric Test Plumbing')->firstOrFail();

    expect($site->status)->toBe(SiteStatus::Onboarding)
        ->and($site->account_id)->toBe($account->id)
        ->and($site->domain_url)->toBe('https://eric-test.com');
});

it('defaults a new site to Onboarding when status is left untouched', function () {
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm(['account_id' => $account->id, 'brand_name' => 'Minimal Co'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Site::query()->where('brand_name', 'Minimal Co')->firstOrFail()->status)
        ->toBe(SiteStatus::Onboarding);
});

it('requires an account and a name', function () {
    Livewire::test(CreateSite::class)
        ->fillForm(['account_id' => null, 'brand_name' => null])
        ->call('create')
        ->assertHasFormErrors(['account_id', 'brand_name']);
});

it('wizard step 1 defines what a site is, to deter per-location sites', function () {
    Livewire::test(CreateSite::class)
        ->assertSee('A site is one WordPress install');
});

it('creation no longer wires WordPress — that is now the guided flow Step 2', function () {
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm([
            'account_id' => $account->id,
            'brand_name' => 'Onboarding Co',
            'domain_url' => 'https://onboarding-co.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->where('brand_name', 'Onboarding Co')->firstOrFail();

    // No connection at create — WordPress is connected + prepped at Step 2.
    expect(Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        // Creation initializes one continuous setup_state at Step 1 and selects the working site.
        ->and(SetupState::query()->where('site_id', $site->id)->exists())->toBeTrue()
        ->and(session('guided_site_id'))->toBe($site->id);
});

it('lands the operator in the guided flow (Step 1) after creating a site', function () {
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm(['account_id' => $account->id, 'brand_name' => 'Redirect Co'])
        ->call('create')
        ->assertRedirect(Business::getUrl());
});
