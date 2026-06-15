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

it('saves the selected candidate (palette + structure) and pushes it', function () {
    Http::fake(['*/wp-json/launchpad/v1/brand-kit' => Http::response(
        ['updated' => true, 'kit_id' => 8, 'wf_tokens_set' => 10, 'structure_set' => true],
    )]);

    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'u', 'app_password' => 'pw'],
    ]);

    // The post-generate form state: a chosen candidate + the resolved structure.
    $candidate = [
        'palette' => [
            'primary' => '#1b3a5b', 'secondary' => '#3e6e9e', 'accent' => '#b25c00', 'text' => '#1a1a1a',
            'text_muted' => '#5b6470', 'bg' => '#ffffff', 'bg_alt' => '#f4f6f8', 'border' => '#e2e6eb',
        ],
        'typography' => ['heading' => 'Archivo', 'body' => 'Inter'],
        'rationale' => 'Navy + amber for plumbing.',
        'recommended' => true,
        'adjustments' => [],
    ];

    Livewire::test(ListSites::class)->callTableAction('brand', $site, data: [
        'industry' => 'plumbing',
        'personality' => 'trustworthy',
        'candidates' => [$candidate],
        'selected' => '0',
        'resolved_structure' => 'bold',
    ]);

    $branding = SiteBranding::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();

    expect($branding->palette)->toBe($candidate['palette'])
        ->and($branding->typography)->toBe(['heading' => 'Archivo', 'body' => 'Inter'])
        ->and($branding->structure_preset)->toBe('bold');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/wp-json/launchpad/v1/brand-kit')
        && $r['wf_tokens']['--wf-color-primary'] === '#1b3a5b'
        && $r['structure'] === 'bold');
});
