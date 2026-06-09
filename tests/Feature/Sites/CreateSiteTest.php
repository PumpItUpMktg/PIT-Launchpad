<?php

use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
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
