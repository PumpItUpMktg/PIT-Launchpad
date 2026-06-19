<?php

use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Prune\PruneEngine;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function defaultsSite(): Site
{
    $site = Site::factory()->create(); // bar = 100 (config default, no override)
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $make = fn (array $a) => Spoke::factory()->create(array_merge([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'status' => SpokeStatus::Candidate, 'page_type' => SpokePageType::Service,
    ], $a));

    // A real silo: pillar + a core above the bar + a core below + a supporting lean-in.
    $make(['silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'volume' => null]);
    $make(['silo' => 'Pumps', 'name' => 'Install', 'tag' => SpokeTag::Core, 'volume' => 300]);          // ≥ 100 → own page
    $make(['silo' => 'Pumps', 'name' => 'Repair', 'tag' => SpokeTag::Core, 'volume' => 40]);            // < 100 → fold to pillar
    $make(['silo' => 'Pumps', 'name' => 'Gutters', 'tag' => SpokeTag::Connecting, 'volume' => 90]);     // supporting → fold to core

    // A dead silo: brand axis, no core clears the bar, ~0 total volume.
    $make(['silo' => 'Brands We Service', 'name' => 'Brands We Service', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'volume' => 0]);
    $make(['silo' => 'Brands We Service', 'name' => 'Zoeller', 'tag' => SpokeTag::Core, 'volume' => 0]);

    return $site;
}

function dspk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('defaults: pillar → hub; core ≥ bar → page; core < bar → fold to pillar; supporting → fold to most-related core', function () {
    $site = defaultsSite();
    $defaults = app(PruneEngine::class)->plan($site)->defaults();

    $pillar = dspk($site, 'Pumps');
    $install = dspk($site, 'Install');
    $repair = dspk($site, 'Repair');
    $gutters = dspk($site, 'Gutters');

    expect($defaults[$pillar->id])->toBe(['bucket' => 'pillar', 'disposition' => 'hub', 'fold_into' => null])
        ->and($defaults[$install->id])->toBe(['bucket' => 'core', 'disposition' => 'page', 'fold_into' => null])
        ->and($defaults[$repair->id])->toBe(['bucket' => 'core', 'disposition' => 'fold', 'fold_into' => $pillar->id])      // folds to its pillar
        ->and($defaults[$gutters->id])->toBe(['bucket' => 'supporting', 'disposition' => 'fold', 'fold_into' => $install->id]); // most-related core
});

test('deadSilos flags the brand axis (no core clears the bar, sub-bar total), not the real silo', function () {
    $site = defaultsSite();

    expect(app(PruneEngine::class)->plan($site)->deadSilos())->toBe(['Brands We Service']);
});

test('a per-site bar override moves the page/fold line', function () {
    $site = defaultsSite();
    $site->forceFill(['silo_own_page_bar' => 30])->save(); // Repair (40) now clears the bar

    $defaults = app(PruneEngine::class)->plan($site->refresh())->defaults();

    expect($defaults[dspk($site, 'Repair')->id]['disposition'])->toBe('page');
});
