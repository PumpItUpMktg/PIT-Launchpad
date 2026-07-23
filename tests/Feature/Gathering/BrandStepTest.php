<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\BrandStep;
use App\Filament\Pages\Gathering\LaunchStep;
use App\Filament\Pages\Gathering\SilosStep;
use App\Filament\Pages\Guided\Brand;
use App\Gathering\LaunchReadiness;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\User;
use App\Publishing\TenantStorage;
use App\Styling\StyleVariation;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * New Setup step 7 — Brand: the look + narrative surface, hosting the same ManagesBrandKit
 * behavior as the guided Brand step it supersedes (voice lives on the Interview/Voice steps).
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

it('the style picker offers all 10 variations — logo palette first, the AI pick second, six-color previews', function () {
    // A logo palette so the logo-derived option (slot 1) exists.
    SiteBranding::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $this->site->id,
        'logo_set' => ['url' => 'https://cdn.example/logo.png', 'primary' => '#123B6B', 'accent' => '#1D6FD6'],
    ]);

    $options = Livewire::test(BrandStep::class)->instance()->styleOptions;

    // Slot 1 = the logo-derived palette; slot 2 = the AI/voice recommendation; then the rest → 11 total.
    expect($options)->toHaveCount(11)
        ->and($options[0]['key'])->toBe('brand_colors')
        ->and($options[0]['badge'])->toBe('From your logo')
        ->and($options[1]['badge'])->toBe('AI pick')             // the recommendation is second
        ->and($options[0]['swatches'])->toHaveCount(6);          // six-role preview, not two

    // Every curated variation is present and each carries a 6-swatch palette.
    $keys = array_column($options, 'key');
    foreach (StyleVariation::cases() as $v) {
        expect($keys)->toContain($v->value);
    }
});

it('choosing one of the new variations records the override', function () {
    Livewire::test(BrandStep::class)->call('chooseStyle', 'midnight');

    expect($this->site->fresh()->style_variation)->toBe(StyleVariation::Midnight)
        ->and($this->site->fresh()->use_logo_colors)->toBeFalse();
});

it('is Setup step 7 (rail order 7·8·9 kept); nav-final keeps it out of the sidebar; guided Brand superseded', function () {
    // Nav-final: the single "Setup" entry registers, not the steps — but the rail order is metadata
    // that survives (Brand 7, Silos 8, Launch 9).
    expect(BrandStep::shouldRegisterNavigation())->toBeFalse()
        ->and(BrandStep::getNavigationGroup())->toBe('Setup')
        ->and(BrandStep::getNavigationSort())->toBe(7)
        ->and(SilosStep::getNavigationSort())->toBe(8)
        ->and(LaunchStep::getNavigationSort())->toBe(9)
        ->and(Brand::menuTag())->toBe('setup');

    config()->set('launchpad.new_setup_enabled', false);
    expect(BrandStep::shouldRegisterNavigation())->toBeFalse();
});

it('saves the narrative (story / mission verbatim / values / differentiators) into SiteNarrative', function () {
    Livewire::test(BrandStep::class)
        ->set('story', 'Second-generation family shop.')
        ->set('mission', 'Keep every basement dry.')
        ->set('valuesText', "Honesty\nCraft")
        ->set('differentiatorsText', 'Lifetime transferable warranty')
        ->call('saveNarrative')
        ->assertNotified();

    $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->first();
    expect($narrative->story)->toBe('Second-generation family shop.')
        ->and($narrative->mission)->toBe('Keep every basement dry.')
        ->and($narrative->mission_raw)->toBeNull() // verbatim by default — no polish
        ->and($narrative->values)->toBe(['Honesty', 'Craft'])
        ->and($narrative->differentiators)->toBe(['Lifetime transferable warranty']);

    // Reload round-trips the saved narrative back into the form.
    Livewire::test(BrandStep::class)->assertSet('story', 'Second-generation family shop.');
});

it('picks a style and gates the push on a WordPress connection (step 6), then applies and stamps brand_pushed', function () {
    $page = Livewire::test(BrandStep::class)->call('chooseStyle', 'warm');
    expect($this->site->fresh()->style_variation)->toBe(StyleVariation::Warm);

    // No WP connection yet → the push is a guarded no-op pointing at Connections & Feeds.
    $page->call('pushBrand')->assertNotified();
    expect((bool) SetupState::query()->where('site_id', $this->site->id)->value('brand_pushed'))->toBeFalse();

    // Connected → the activator pushes the chosen variation and the flag stamps.
    Connection::factory()->create(['site_id' => $this->site->id, 'provider' => 'wp_app_password']);
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyleVariation')->once()->with('warm', Mockery::type('array'))->andReturn(['updated' => true, 'variation' => 'warm']);
    $client->shouldIgnoreMissing();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    Livewire::test(BrandStep::class)->call('pushBrand');

    expect(SetupState::query()->where('site_id', $this->site->id)->value('brand_pushed'))->toBe(true)
        // …and the Launch checklist's brand item now points at THIS step, green.
        ->and(collect(app(LaunchReadiness::class)->checklist($this->site))->keyBy('key')['brand'])
        ->toMatchArray(['ok' => true, 'url' => BrandStep::getUrl()]);
});

it('team members persist immediately on add and remove', function () {
    $page = Livewire::test(BrandStep::class)
        ->set('newTeamName', 'Dana Rivera')
        ->set('newTeamRole', 'Lead installer')
        ->call('addTeamMember');

    $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->first();
    expect($narrative->team)->toHaveCount(1)
        ->and($narrative->team[0]['name'])->toBe('Dana Rivera');

    $page->call('removeTeamMember', 0);
    expect(SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->first()->team)->toBeNull();
});

it('restores the logo upload — stored on SiteBranding via the real intake; remove clears it', function () {
    Storage::fake(TenantStorage::DISK);

    // Upload → the real LogoIntake stores it to (faked) R2 and persists logo_set; property clears.
    $page = Livewire::test(BrandStep::class)
        ->set('logoUpload', UploadedFile::fake()->image('brand.png', 120, 60))
        ->assertNotified()
        ->assertSet('logoUpload', null)
        ->assertSet('logoInfo.url', fn ($url) => is_string($url) && $url !== '');

    $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->first();
    expect($branding->logo_set['url'] ?? null)->not->toBeNull()
        ->and($branding->logo_set['ext'] ?? null)->toBe('png');

    // Remove clears the stored logo source and the logo-colors style choice.
    $this->site->update(['use_logo_colors' => true]);
    $page->call('removeLogo')->assertSet('logoInfo', null);
    expect($this->site->fresh()->use_logo_colors)->toBeFalse()
        ->and(SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->first()->logo_set['url'] ?? null)->toBeNull();
});
