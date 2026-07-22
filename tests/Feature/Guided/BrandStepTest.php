<?php

use App\Enums\UserRole;
use App\Enums\VoiceStatus;
use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\WhereYouWork;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\Claude\CompletionResult;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Onboarding\MissionPolisher;
use App\Publishing\TenantStorage;
use App\Styling\StyleVariation;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

function activeVoice(string $siteId): ?VoiceProfile
{
    return VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $siteId)->where('status', VoiceStatus::Active->value)->first();
}

function brandNarrative(string $siteId): ?SiteNarrative
{
    return SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->first();
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

test('the "Your brand colors" option is gated on a usable logo palette and is selectable', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    // No logo → the 4th option is not rendered.
    Livewire::test(Brand::class)->assertDontSee('Your brand colors');

    SiteBranding::factory()->create([
        'site_id' => $this->site->id,
        'logo_set' => ['url' => 'https://cdn.example/logo.png', 'primary' => '#EA580C', 'accent' => '#0B1F33'],
    ]);

    // A usable palette → the option appears with its swatches.
    $component = Livewire::test(Brand::class)
        ->assertSee('Your brand colors')
        ->assertSee('pulled from your logo')
        ->assertSee('#ea580c'); // the logo primary swatch

    // Choosing it sets the flag (kept out of style_variation).
    $component->call('chooseStyle', 'brand_colors');
    expect($this->site->fresh()->use_logo_colors)->toBeTrue()
        ->and($this->site->fresh()->style_variation)->toBeNull();

    // Switching to a curated style clears the flag.
    $component->call('chooseStyle', 'warm');
    expect($this->site->fresh()->use_logo_colors)->toBeFalse()
        ->and($this->site->fresh()->style_variation)->toBe(StyleVariation::Warm);
});

test('a monochrome logo still offers the option — accent borrowed from the nearest base', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    SiteBranding::factory()->create([
        'site_id' => $this->site->id,
        'logo_set' => ['url' => 'https://cdn.example/logo.png', 'primary' => '#0B1F33'], // no accent
    ]);

    // Nearest base for cool-dark navy is Bold → its highlight (#e4572e) is borrowed for the swatch.
    Livewire::test(Brand::class)->assertSee('Your brand colors')->assertSee('#e4572e');
});

test('Applying the look activates the style variation, sets brand_pushed, and Continue advances', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true,
    ]);

    // The pivot's brand push = activate a theme.json style variation (no Elementor Global Kit).
    // Mock the WP transport so the real StyleActivator runs (no override / no voice → Clean).
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyle')->once()->with('clean')->andReturn(['updated' => true, 'variation' => 'clean']);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    Livewire::test(Brand::class)->call('pushBrand');

    expect(SetupState::query()->where('site_id', $this->site->id)->value('brand_pushed'))->toBe(true);

    Livewire::test(Brand::class)->call('proceed')->assertRedirect(WhereYouWork::getUrl());
});

test('pushBrand warns when the site is not on a block theme (the push is inert)', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true,
    ]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('activateStyle')->once()->with('clean')->andReturn([
        'updated' => true, 'variation' => 'clean', 'is_block_theme' => false, 'active_colors' => [],
    ]);
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    Livewire::test(Brand::class)
        ->call('pushBrand')
        ->assertNotified("Applied Clean & Trustworthy — but this site isn't on a block theme");
});

test('Choosing a style sets the operator override on the site', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)->call('chooseStyle', 'warm');
    expect($this->site->fresh()->style_variation)->toBe(StyleVariation::Warm);

    // 'auto' clears the override → follow the recommendation again.
    Livewire::test(Brand::class)->call('chooseStyle', 'auto');
    expect($this->site->fresh()->style_variation)->toBeNull();
});

test('Brand step captures the brand narrative into SiteNarrative (blank → null, lines → list)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)
        ->set('story', '  We started with one truck.  ')
        ->set('mission', '   ')                       // whitespace → null
        ->set('valuesText', "On time\n\nQuote first\n")
        ->set('differentiatorsText', "Licensed & insured\nWritten warranty")
        ->call('saveNarrative')
        ->assertOk();

    $n = brandNarrative($this->site->id);
    expect($n)->not->toBeNull()
        ->and($n->story)->toBe('We started with one truck.')
        ->and($n->mission)->toBeNull()
        ->and($n->values)->toBe(['On time', 'Quote first'])           // blank line dropped
        ->and($n->differentiators)->toBe(['Licensed & insured', 'Written warranty']);
});

test('Brand step pre-fills the narrative form from an existing SiteNarrative (mixed shapes → lines)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    SiteNarrative::create([
        'site_id' => $this->site->id,
        'story' => 'Our story',
        'differentiators' => [['title' => 'Fast', 'description' => 'same day'], 'Licensed'], // operator {t,d} + plain string
    ]);

    Livewire::test(Brand::class)
        ->assertSet('story', 'Our story')
        ->assertSet('differentiatorsText', "Fast — same day\nLicensed");
});

test('Mission is stored VERBATIM by default — no AI call, no raw copy', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    // No MissionPolisher binding: resolving it would build the real client — verbatim must never call AI.

    Livewire::test(Brand::class)
        ->set('mission', 'we fix drains rite the first time')
        ->call('saveNarrative')
        ->assertOk();

    $n = brandNarrative($this->site->id);
    expect($n->mission)->toBe('we fix drains rite the first time')  // exactly as written
        ->and($n->mission_raw)->toBeNull();
});

test('Mission enhance opt-in polishes via AI — stores the polished statement, keeps the client wording in mission_raw, shows the result', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    app()->instance(MissionPolisher::class, new MissionPolisher(
        new FakeClaudeClient('We fix every drain right the first time.'),
    ));

    Livewire::test(Brand::class)
        ->set('mission', 'we fix drains rite the first time')
        ->set('missionEnhance', true)
        ->call('saveNarrative')
        ->assertSet('mission', 'We fix every drain right the first time.'); // the client SEES what will render

    $n = brandNarrative($this->site->id);
    expect($n->mission)->toBe('We fix every drain right the first time.')
        ->and($n->mission_raw)->toBe('we fix drains rite the first time');   // their words, kept
});

test('Mission enhance fails OPEN — an AI failure saves the wording verbatim, never blocks the save', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    app()->instance(MissionPolisher::class, new MissionPolisher(new class implements ClaudeClient
    {
        public function complete(string $prompt, ?string $system = null): string
        {
            throw new RuntimeException('api down');
        }

        public function completeDetailed(string $prompt, ?string $system = null): CompletionResult
        {
            throw new RuntimeException('api down');
        }
    }));

    Livewire::test(Brand::class)
        ->set('mission', 'our mission as typed')
        ->set('missionEnhance', true)
        ->call('saveNarrative')
        ->assertOk();

    $n = brandNarrative($this->site->id);
    expect($n->mission)->toBe('our mission as typed')
        ->and($n->mission_raw)->toBeNull();
});

test('Brand step remembers the enhance opt-in — a stored mission_raw pre-checks the box', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    SiteNarrative::create([
        'site_id' => $this->site->id,
        'mission' => 'We fix every drain right the first time.',
        'mission_raw' => 'we fix drains rite the first time',
    ]);

    Livewire::test(Brand::class)
        ->assertSet('mission', 'We fix every drain right the first time.')
        ->assertSet('missionEnhance', true);
});

test('Continuing from Brand persists whatever narrative was entered', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3,
        'services_done' => true, 'deps_ready' => true, 'brand_pushed' => true,
    ]);

    Livewire::test(Brand::class)->set('story', 'Truck story')->call('proceed')->assertRedirect(WhereYouWork::getUrl());

    expect(brandNarrative($this->site->id)?->story)->toBe('Truck story');
});

test('Brand step synthesises and activates a voice profile from the interview', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)
        ->set('voiceTone', 'direct_expert')
        ->set('voiceAudience', 'property managers')
        ->set('voiceCredibility', 'licensed, 20 years')
        ->call('saveVoice')
        ->assertOk();

    $v = activeVoice($this->site->id);
    expect($v)->not->toBeNull()
        ->and($v->version)->toBe(1)
        ->and($v->tone_axes['formality'])->toBe(0.6)              // direct_expert tone
        ->and(data_get($v->audience, 'primary'))->toBe('property managers')
        ->and(data_get($v->persona, 'credibility'))->toBe('licensed, 20 years');
});

test('updating the voice archives the prior active (one active per site)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)->set('voiceAudience', 'homeowners')->call('saveVoice');
    Livewire::test(Brand::class)->set('voiceAudience', 'builders')->call('saveVoice');

    $active = VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $this->site->id)->where('status', VoiceStatus::Active->value)->get();

    expect($active)->toHaveCount(1)                               // exactly one active
        ->and($active->first()->version)->toBe(2)                 // the newer one
        ->and(VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)
            ->where('status', VoiceStatus::Archived->value)->count())->toBe(1);
});

test('Brand step shows voice as set and pre-fills from the active profile', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    VoiceProfile::create([
        'site_id' => $this->site->id, 'version' => 1, 'status' => VoiceStatus::Active,
        'audience' => ['primary' => 'homeowners'], 'persona' => ['credibility' => 'licensed'],
    ]);

    Livewire::test(Brand::class)
        ->assertSet('voiceSet', true)
        ->assertSet('voiceAudience', 'homeowners')
        ->assertSet('voiceCredibility', 'licensed');
});

test('Brand is freely reachable even before WordPress is prepped — tabs, not a gated wizard', function () {
    // services done but WordPress not prepped (deps_ready false) — the page renders anyway; the
    // brand PUSH itself still requires a working WP connection (its own action-level guard).
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => false,
    ]);

    Livewire::test(Brand::class)->assertOk();
});

test('Brand step captures team members — photo stored to the tenant R2 prefix, persisted immediately', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    Storage::fake(TenantStorage::DISK);

    Livewire::test(Brand::class)
        ->set('newTeamName', 'Dana Rivera')
        ->set('newTeamRole', 'Master Plumber')
        ->set('newTeamBio', 'Twenty years in the trade.')
        ->set('teamPhoto', UploadedFile::fake()->image('dana.jpg', 400, 400))
        ->call('addTeamMember')
        ->assertSet('newTeamName', '');                       // inputs cleared after add

    $team = brandNarrative($this->site->id)->team;             // persisted WITHOUT hitting Save
    expect($team)->toHaveCount(1)
        ->and($team[0]['name'])->toBe('Dana Rivera')
        ->and($team[0]['role'])->toBe('Master Plumber')
        ->and($team[0]['photo_url'])->toContain('team-dana-rivera-'); // stored under the tenant prefix

    // Remove persists too.
    Livewire::test(Brand::class)
        ->assertSet('team.0.name', 'Dana Rivera')              // round-trips into the form
        ->call('removeTeamMember', 0);
    expect(brandNarrative($this->site->id)->team)->toBeNull();
});

test('a team member without a photo is captured with an empty photo_url (initials chip renders, never a stock face)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)
        ->set('newTeamName', 'Sam Ortiz')
        ->call('addTeamMember');

    $team = brandNarrative($this->site->id)->team;
    expect($team[0]['name'])->toBe('Sam Ortiz')
        ->and($team[0]['photo_url'])->toBe('');
});
