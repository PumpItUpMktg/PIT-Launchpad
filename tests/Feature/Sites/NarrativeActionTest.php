<?php

use App\ContentEngine\Drafting\GroundingReadiness;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\User;
use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('exposes the Brand narrative action', function () {
    Livewire::test(ListSites::class)->assertTableActionExists('narrative');
});

it('captures brand narrative and flips an About page from held-intake to generatable', function () {
    $site = Site::factory()->create();
    SiteBranding::factory()->create(['site_id' => $site->id]); // baseline narrative grounding
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::where('name', 'about-page')->firstOrFail();
    $about = Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Utility, 'standard_type' => StandardPageType::About,
        'wireframe_kit_id' => $kit->id, 'slot_payload' => [],
    ]);

    // Before capture: required story absent → held.
    expect(app(GroundingReadiness::class)->ready($about->fresh()))->toBeFalse();

    Livewire::test(ListSites::class)->callTableAction('narrative', $site, data: [
        'story' => 'We started with one truck and a promise: show up on time, do honest work.',
        'mission' => 'Make plumbing painless for local homeowners.',
        'values' => [['title' => 'On time', 'description' => 'Every visit.']],
        'differentiators' => [],
    ]);

    $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();

    expect($narrative->story)->toContain('one truck')
        ->and($narrative->values)->toHaveCount(1)
        // captured story satisfies the required intake → the page is now generatable
        ->and(app(GroundingReadiness::class)->ready($about->fresh()))->toBeTrue();
});

it('stores blank text + empty repeaters as null (degrade, not empty strings)', function () {
    $site = Site::factory()->create();

    Livewire::test(ListSites::class)->callTableAction('narrative', $site, data: [
        'story' => 'A real story.',
        'mission' => '   ',          // whitespace
        'values' => [],             // none added
        'differentiators' => [],
    ]);

    $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();

    expect($narrative->story)->toBe('A real story.')
        ->and($narrative->mission)->toBeNull()           // whitespace → null
        ->and($narrative->values)->toBeNull()            // empty → null
        ->and($narrative->differentiators)->toBeNull();
});
