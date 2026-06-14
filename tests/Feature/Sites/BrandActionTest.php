<?php

use App\Enums\ConnectionProvider;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Connection;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('exposes the Generate brand action', function () {
    Livewire::test(ListSites::class)->assertTableActionExists('brand');
});

it('saves the reviewed brand and pushes it to the Global Kit', function () {
    Http::fake(['*/wp-json/launchpad/v1/brand-kit' => Http::response(
        ['updated' => true, 'kit_id' => 8, 'colors_set' => 3, 'fonts_set' => 2],
    )]);

    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);

    Livewire::test(ListSites::class)->callTableAction('brand', $site, data: [
        'industry' => 'plumbing',
        'personality' => 'trustworthy',
        'primary' => '#0f62fe',
        'accent' => '#ff6f00',
        'text' => '#1a1a1a',
        'heading_font' => 'Montserrat',
        'body_font' => 'Inter',
    ]);

    $branding = SiteBranding::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();

    expect($branding->palette)->toBe(['primary' => '#0f62fe', 'accent' => '#ff6f00', 'text' => '#1a1a1a'])
        ->and($branding->typography)->toBe(['heading' => 'Montserrat', 'body' => 'Inter']);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/brand-kit')
        && $r['colors']['primary'] === '#0f62fe');
});
