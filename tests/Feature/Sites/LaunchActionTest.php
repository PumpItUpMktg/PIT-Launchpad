<?php

use App\Enums\ConnectionProvider;
use App\Enums\LaunchRunStatus;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Connection;
use App\Models\LaunchRun;
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

function launchRunFor(string $siteId): LaunchRun
{
    return LaunchRun::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->firstOrFail();
}

it('the Launch site action refuses a site without a verified connection', function () {
    $site = Site::factory()->create();
    Http::fake();

    Livewire::test(ListSites::class)->callTableAction('launch', $site);

    Http::assertNothingSent();
    expect(launchRunFor($site->id)->status)->toBe(LaunchRunStatus::Blocked);
});

it('the Launch site action runs the orchestrator for a verified site', function () {
    $site = Site::factory()->create();
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.test', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);
    Http::fake(['*/launchpad/v1/redirects' => Http::response(['count' => 0])]);

    Livewire::test(ListSites::class)->callTableAction('launch', $site);

    expect(launchRunFor($site->id)->status)->toBe(LaunchRunStatus::Completed);
});
