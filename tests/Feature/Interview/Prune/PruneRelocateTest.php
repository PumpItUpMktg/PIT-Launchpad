<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\SiloPrune;
use App\Interview\Prune\PruneEngine;
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

/** Two silos: Sump Pumps (pillar + a core page) and Backup Power (pillar + a spoke to relocate). */
function relocateSite(): Site
{
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $make = fn (array $a) => Spoke::factory()->create(array_merge([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'status' => SpokeStatus::Candidate, 'page_type' => SpokePageType::Service, 'tag' => SpokeTag::Core,
    ], $a));

    $make(['silo' => 'Sump Pumps', 'name' => 'Sump Pumps', 'is_pillar' => true]);
    $make(['silo' => 'Sump Pumps', 'name' => 'Battery Backup Sump Pump', 'volume' => 200]); // a core page to fold onto
    $make(['silo' => 'Backup Power', 'name' => 'Backup Power', 'is_pillar' => true]);
    $make(['silo' => 'Backup Power', 'name' => 'Battery Backup System', 'volume' => 20]);    // the spoke to relocate

    return $site;
}

function rspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('engine moveSpoke relocates a spoke cross-silo as a folded section (Offered — never deleted)', function () {
    $site = relocateSite();
    $spoke = rspk($site, 'Battery Backup System');
    $target = rspk($site, 'Battery Backup Sump Pump');

    expect(app(PruneEngine::class)->moveSpoke($site, $spoke->id, 'Sump Pumps', SpokeGranularity::Folded, $target->id))->toBeTrue();

    $spoke->refresh();
    expect($spoke->silo)->toBe('Sump Pumps')                  // re-homed
        ->and($spoke->granularity)->toBe(SpokeGranularity::Folded)
        ->and($spoke->fold_into_id)->toBe($target->id)        // lands on the core page
        ->and($spoke->status)->toBe(SpokeStatus::Offered);    // floor: a section, not dropped
});

test('engine moveSpoke promote clears the fold target; refuses a pillar', function () {
    $site = relocateSite();
    $spoke = rspk($site, 'Battery Backup System');
    app(PruneEngine::class)->moveSpoke($site, $spoke->id, null, SpokeGranularity::Folded, rspk($site, 'Backup Power')->id);

    app(PruneEngine::class)->moveSpoke($site, $spoke->id, null, SpokeGranularity::OwnPage, null);
    expect($spoke->refresh()->fold_into_id)->toBeNull()
        ->and($spoke->granularity)->toBe(SpokeGranularity::OwnPage);

    $pillar = rspk($site, 'Backup Power');
    expect(app(PruneEngine::class)->moveSpoke($site, $pillar->id, 'Sump Pumps', SpokeGranularity::Folded, null))->toBeFalse()
        ->and($pillar->refresh()->silo)->toBe('Backup Power'); // pillar untouched
});

it('auto-folds a silo the instant the dropdown changes — card collapses, spokes re-home (no Update)', function () {
    $site = relocateSite();

    $page = Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->set('siloDecisions.Backup Power.fold_into', 'Sump Pumps'); // updated hook fires immediately

    expect(rspk($site, 'Battery Backup System')->silo)->toBe('Sump Pumps')
        ->and(array_keys($page->instance()->bySilo))->not->toContain('Backup Power')
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->toBeNull(); // not committed
});

it('auto promote/demote a spoke the instant its disposition toggle changes', function () {
    $site = relocateSite();
    $spoke = rspk($site, 'Battery Backup System');

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->set("spokeDecisions.{$spoke->id}.granularity", 'own_page'); // updated hook → promote

    expect($spoke->refresh()->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($spoke->status)->toBe(SpokeStatus::Offered)
        ->and($spoke->fold_into_id)->toBeNull();
});

it('moveSpoke re-targets a spoke onto a core page in another silo (the drag/select path)', function () {
    $site = relocateSite();
    $spoke = rspk($site, 'Battery Backup System');
    $target = rspk($site, 'Battery Backup Sump Pump');

    Livewire::test(SiloPrune::class)
        ->set('siteId', $site->id)
        ->call('start')
        ->call('moveSpoke', $spoke->id, 'core', $target->id);

    $spoke->refresh();
    expect($spoke->silo)->toBe('Sump Pumps')
        ->and($spoke->granularity)->toBe(SpokeGranularity::Folded)
        ->and($spoke->fold_into_id)->toBe($target->id);
});

test('engine promoteToOwnSilo turns a folded section into its own silo (a new hub page)', function () {
    $site = relocateSite();
    $spoke = rspk($site, 'Battery Backup System'); // currently a spoke under Backup Power

    expect(app(PruneEngine::class)->promoteToOwnSilo($site, $spoke->id))->toBeTrue();

    $spoke->refresh();
    expect($spoke->silo)->toBe('Battery Backup System')       // its own grouping…
        ->and($spoke->is_pillar)->toBeTrue()                  // …as the hub page
        ->and($spoke->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($spoke->tag)->toBe(SpokeTag::Core)
        ->and($spoke->fold_into_id)->toBeNull();
});

test('promoteToOwnSilo refuses a spoke that already heads a silo', function () {
    $site = relocateSite();
    $pillar = rspk($site, 'Sump Pumps'); // already a pillar

    expect(app(PruneEngine::class)->promoteToOwnSilo($site, $pillar->id))->toBeFalse();
});
