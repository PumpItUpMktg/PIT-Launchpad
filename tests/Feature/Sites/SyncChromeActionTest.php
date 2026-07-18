<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('exposes the Sync header & footer action', function () {
    Livewire::test(ListSites::class)->assertTableActionExists('syncChrome');
});

it('pushes the site profile (brand + nav) to the companion plugin', function () {
    Http::fake(['*/wp-json/launchpad/v1/site-profile' => Http::response(['updated' => true], 200)]);

    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);
    Location::factory()->create(['site_id' => $site->id, 'phone' => '(973) 555-0100']);
    // A real service page → the header nav link the chrome carries.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'sump-pump-repair', 'title' => 'Sump Pump Repair',
    ]);

    Livewire::test(ListSites::class)->callTableAction('syncChrome', $site);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/site-profile')
        && $r['brand_name'] === 'Sump Pump Gurus'
        && collect($r['services'])->pluck('label')->contains('Sump Pump Repair'));
});

it('saves the header-tone override and pushes it', function () {
    Http::fake(['*/wp-json/launchpad/v1/site-profile' => Http::response(['updated' => true], 200)]);

    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);

    // Operator forces the light bar.
    Livewire::test(ListSites::class)->callTableAction('syncChrome', $site, data: ['header_tone' => 'light']);

    expect($site->fresh()->header_tone_override)->toBe('light');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/site-profile')
        && $r['header_tone'] === 'light');

    // Auto clears the override back to null.
    Livewire::test(ListSites::class)->callTableAction('syncChrome', $site, data: ['header_tone' => '']);
    expect($site->fresh()->header_tone_override)->toBeNull();
});

it('surfaces a graceful failure when the site has no WordPress connection', function () {
    $site = Site::factory()->create(['brand_name' => 'No Connection Co']);

    // No connection → forSite throws WordpressException → the action reports it, never fatals.
    Livewire::test(ListSites::class)
        ->callTableAction('syncChrome', $site)
        ->assertHasNoErrors();

    Http::assertNothingSent();
});
