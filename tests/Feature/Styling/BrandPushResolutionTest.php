<?php

use App\Enums\ConnectionProvider;
use App\Enums\UserRole;
use App\Enums\VoiceStatus;
use App\Filament\Pages\Gathering\BrandStep;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Styling\StyleVariation;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Regression cover for the "reverts to logo blue when re-pushed through the dashboard" report. The
 * trap is a sticky `use_logo_colors` flag shadowing a curated pick — this pins both the push behaviour
 * (a curated override IS honoured) and the pre-push diagnostic that makes the logo-colors state legible.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $this->site = Site::factory()->create([
        'style_variation' => StyleVariation::Slate->value,
        'use_logo_colors' => false,
    ]);
    session(['guided_site_id' => $this->site->id, 'gathering_site_id' => $this->site->id, 'active_tenant_id' => $this->site->id]);

    SiteBranding::factory()->create([
        'site_id' => $this->site->id,
        'logo_set' => ['url' => 'https://cdn.example/logo.png', 'primary' => '#0B1F33'], // the navy "logo blue"
    ]);
    VoiceProfile::create([
        'site_id' => $this->site->id, 'version' => 1, 'status' => VoiceStatus::Active,
        'audience' => ['primary' => 'homeowners'], 'persona' => ['credibility' => 'licensed'],
    ]);
    Connection::create([
        'site_id' => $this->site->id, 'provider' => ConnectionProvider::WpAppPassword,
        'credentials' => ['base_url' => 'https://x', 'username' => 'u', 'app_password' => 'p'],
    ]);
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 7]);
});

test('re-pushing with a curated override honours it — never reverts to the logo palette', function () {
    $client = Mockery::mock(WordpressClient::class);
    // The curated push sends the variation's palette inline under its own slug ('slate'), never the
    // logo-derived 'brand' slug — that's the "never reverts to logo" guarantee.
    $client->shouldReceive('activateStyleVariation')->once()->with('slate', Mockery::type('array'))
        ->andReturn(['updated' => true, 'variation' => 'slate', 'is_block_theme' => true, 'active_colors' => ['primary' => '#0f172a']]);
    $client->shouldReceive('activateStyleVariation')->with('brand', Mockery::any())->never(); // the logo-derived path must NOT fire
    $client->shouldReceive('pushSiteProfile')->andReturn([]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    Livewire::test(BrandStep::class)->call('pushBrand');
});

test('the resolution diagnostic names the curated pick when the logo flag is off', function () {
    $res = Livewire::test(BrandStep::class)->instance()->getStyleResolutionProperty();

    expect($res['is_logo'])->toBeFalse()
        ->and($res['shadows_curated'])->toBeFalse()
        ->and($res['label'])->toBe(StyleVariation::Slate->label());
});

test('the resolution diagnostic flags the logo flag shadowing a curated pick (the drift the report describes)', function () {
    $this->site->update(['use_logo_colors' => true]); // the sticky flag re-enabled

    $res = Livewire::test(BrandStep::class)->instance()->getStyleResolutionProperty();

    expect($res['is_logo'])->toBeTrue()
        ->and($res['shadows_curated'])->toBeTrue()
        ->and($res['curated_label'])->toBe(StyleVariation::Slate->label())
        ->and($res['label'])->toContain('logo');
});
