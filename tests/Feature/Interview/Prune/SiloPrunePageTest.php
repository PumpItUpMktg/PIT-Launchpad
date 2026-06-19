<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\SiloPrune;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function prunePageSite(): Site
{
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $make = fn (array $a) => Spoke::factory()->create(array_merge([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps',
        'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage,
    ], $a));

    $make(['name' => 'Sump Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'Sump Pump Installation', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'volume' => 300]);
    $make(['name' => 'Gutter Installation', 'tag' => SpokeTag::Connecting, 'page_type' => SpokePageType::Service, 'connection_note' => 'gutters cause basement water', 'volume' => 90]);
    $make(['name' => 'General Plumbing', 'silo' => 'Out of Lane', 'tag' => SpokeTag::Fringe, 'page_type' => SpokePageType::Service]);

    return $site;
}

function pspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

it('shows an empty-state for a site with no persisted candidate tree', function () {
    $site = Site::factory()->create(); // no blueprint / spokes

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->assertSee('No candidate tree yet');
});

it('opens the prune pre-decided: core ≥ bar pre-checked own page, supporting pre-folded', function () {
    $site = prunePageSite();
    $install = pspk($site, 'Sump Pump Installation'); // core, volume 300 ≥ 100 bar → own page
    $gutter = pspk($site, 'Gutter Installation');     // connecting (supporting) → fold

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->assertSet('started', true)
        ->assertSet("spokeDecisions.{$install->id}.outcome", 'offer')      // stated service → offered (floor)
        ->assertSet("spokeDecisions.{$install->id}.granularity", 'own_page') // ≥ bar → own page
        ->assertSet("spokeDecisions.{$gutter->id}.outcome", 'offer')        // supporting kept (folded section)
        ->assertSet("spokeDecisions.{$gutter->id}.granularity", 'folded')   // default fold
        ->assertSee('Sump Pump Installation')
        ->assertSee('gutters cause basement water'); // connecting note shown
});

it('batch-confirms a silo core via confirmCore; supporting keeps its folded default', function () {
    $site = prunePageSite();
    $pillar = pspk($site, 'Sump Pumps');
    $install = pspk($site, 'Sump Pump Installation');
    $gutter = pspk($site, 'Gutter Installation');

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->call('confirmCore', 'Sump Pumps')
        ->assertSet("spokeDecisions.{$pillar->id}.outcome", 'offer')
        ->assertSet("spokeDecisions.{$install->id}.outcome", 'offer')
        ->assertSet("spokeDecisions.{$gutter->id}.granularity", 'folded'); // supporting untouched at its fold default
});

it('saves a resumable draft and reloads it on the next open', function () {
    $site = prunePageSite();
    $gutter = pspk($site, 'Gutter Installation');

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->set("spokeDecisions.{$gutter->id}.outcome", 'capture')
        ->call('saveDraft');

    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('prune_draft'))
        ->not->toBeNull();

    // a fresh open resumes the saved decision
    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->assertSet("spokeDecisions.{$gutter->id}.outcome", 'capture');
});

it('previews build-vs-drop from the draft and finalizes through the engine, dropping pending', function () {
    $site = prunePageSite();
    $pillar = pspk($site, 'Sump Pumps');
    $install = pspk($site, 'Sump Pump Installation');
    $gutter = pspk($site, 'Gutter Installation');

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        // decide pillar + install + a re-tagged connecting→core; leave nothing else
        ->set("spokeDecisions.{$pillar->id}.outcome", 'offer')
        ->set("spokeDecisions.{$install->id}.outcome", 'offer')
        ->set("spokeDecisions.{$gutter->id}.outcome", 'offer')
        ->set("spokeDecisions.{$gutter->id}.tag", 'core')
        ->assertSet('preview.built', 3)
        ->assertSet('preview.pending', 0)
        ->call('finalize')
        ->assertSet('finalized', true);

    expect(pspk($site, 'Sump Pump Installation')->status)->toBe(SpokeStatus::Offered)
        ->and(pspk($site, 'Gutter Installation')->tag)->toBe(SpokeTag::Core)           // re-tag landed
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->not->toBeNull()
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('prune_draft'))->toBeNull();
});

it('finalize applies the pre-decided default to an untouched supporting spoke (folded, never dropped)', function () {
    $site = prunePageSite();

    // Touch nothing beyond opening — the seeded defaults carry. Gutter (supporting) defaults to fold.
    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->call('finalize');

    $gutter = pspk($site, 'Gutter Installation');
    expect($gutter->status)->toBe(SpokeStatus::Offered)                  // built as a folded section, NOT dropped (stated-service floor)
        ->and($gutter->granularity)->toBe(SpokeGranularity::Folded)
        ->and($gutter->fold_into_id)->not->toBeNull()                    // folded into its most-related core page
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->not->toBeNull();
});

it('folds a thin silo from the decision-set on finalize', function () {
    $site = prunePageSite();
    // add a second thin silo
    $bp = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sewage Pumps', 'is_pillar' => true, 'name' => 'Sewage Pumps', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate]);

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->call('confirmCore', 'Sump Pumps')
        ->call('confirmCore', 'Sewage Pumps')
        ->set('siloDecisions.Sewage Pumps.fold_into', 'Sump Pumps')
        ->set('spokeDecisions.'.pspk($site, 'Gutter Installation')->id.'.outcome', 'skip')
        ->call('finalize');

    expect(pspk($site, 'Sewage Pumps')->silo)->toBe('Sump Pumps')
        ->and(pspk($site, 'Sewage Pumps')->is_pillar)->toBeFalse();
});
