<?php

use App\Enums\ConnectionProvider;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
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

it('wizard step 2 offers an in-panel Test connection action', function () {
    Livewire::test(CreateSite::class)
        ->assertSee('Test connection');
});

it('wires a verified WordPress connection from the wizard when credentials are given', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200)]);
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm([
            'account_id' => $account->id,
            'brand_name' => 'Connected Co',
            'domain_url' => 'https://connected-co.com',
            'app_password' => 'abcd efgh ijkl mnop',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->where('brand_name', 'Connected Co')->firstOrFail();
    $connection = Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();

    expect($connection->provider)->toBe(ConnectionProvider::WpAppPassword)
        ->and($connection->credentials['base_url'])->toBe('https://connected-co.com') // defaulted from Site URL
        ->and($connection->credentials['username'])->toBe('launchpad-sync')
        ->and($connection->compromised)->toBeFalse(); // verify-before-store → passes the §9 gate
});

it('keeps the site even when WordPress verification fails (never loses the tenant)', function () {
    Http::fake(['*' => Http::response('', 401)]);
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm([
            'account_id' => $account->id,
            'brand_name' => 'Unverified Co',
            'domain_url' => 'https://unverified-co.com',
            'app_password' => 'wrongpass1234',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->where('brand_name', 'Unverified Co')->firstOrFail();
    expect(Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('creates no connection when the WordPress password is left blank', function () {
    $account = Account::factory()->create();

    Livewire::test(CreateSite::class)
        ->fillForm([
            'account_id' => $account->id,
            'brand_name' => 'No WP Co',
            'domain_url' => 'https://no-wp-co.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $site = Site::query()->where('brand_name', 'No WP Co')->firstOrFail();
    expect(Connection::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
