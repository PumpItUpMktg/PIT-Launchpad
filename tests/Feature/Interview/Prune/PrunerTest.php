<?php

use App\Enums\PruneOutcome;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Interview\Prune\Pruner;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function pruneSite(): Site
{
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    $make = fn (array $attrs) => Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => $bp->id,
        'silo' => 'Sump Pumps',
        'status' => SpokeStatus::Candidate,
        'granularity' => SpokeGranularity::OwnPage,
    ], $attrs));

    $make(['name' => 'Sump Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'Sump Pump Installation', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'Why Is My Basement Wet?', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Content]);
    $make(['name' => 'Gutter Installation', 'tag' => SpokeTag::Connecting, 'page_type' => SpokePageType::Service, 'connection_note' => 'gutters cause basement water']);
    $make(['name' => 'Curtain Drain', 'tag' => SpokeTag::Adjacent, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'General Plumbing', 'silo' => 'Out of Lane', 'tag' => SpokeTag::Fringe, 'page_type' => SpokePageType::Service]);

    return $site;
}

function spk(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('the plan lists decidable candidates and excludes fringe from the gate', function () {
    $plan = app(Pruner::class)->plan(pruneSite());

    expect($plan->decidable())->toHaveCount(5)   // 4 spokes + pillar, minus fringe
        ->and($plan->fringe())->toHaveCount(1)
        ->and($plan->pending())->toHaveCount(5)
        ->and($plan->isComplete())->toBeFalse()
        ->and($plan->confirmed)->toBeFalse();
});

test('apply routes candidates per the table (status + page type)', function () {
    $site = pruneSite();

    app(Pruner::class)->apply($site, [
        'Sump Pump Installation' => PruneOutcome::Offer,
        'Gutter Installation' => 'future',
        'Curtain Drain' => 'skip',
        'Why Is My Basement Wet?' => 'capture',
    ]);

    expect(spk($site, 'Sump Pump Installation')->status)->toBe(SpokeStatus::Offered)
        ->and(spk($site, 'Sump Pump Installation')->page_type)->toBe(SpokePageType::Service)
        ->and(spk($site, 'Gutter Installation')->status)->toBe(SpokeStatus::Future)
        ->and(spk($site, 'Curtain Drain')->status)->toBe(SpokeStatus::Skipped)
        ->and(spk($site, 'Why Is My Basement Wet?')->status)->toBe(SpokeStatus::Content);
});

test('a capture decision converts a service spoke to a content-path page', function () {
    $site = pruneSite();

    app(Pruner::class)->apply($site, ['Gutter Installation' => PruneOutcome::Capture]);

    expect(spk($site, 'Gutter Installation')->status)->toBe(SpokeStatus::Content)
        ->and(spk($site, 'Gutter Installation')->page_type)->toBe(SpokePageType::Content);
});

test('accept-core offers core service spokes and keeps core content as capture', function () {
    $site = pruneSite();

    $count = app(Pruner::class)->acceptCore($site);

    expect($count)->toBe(3) // pillar + installation (service) + basement-wet (content)
        ->and(spk($site, 'Sump Pumps')->status)->toBe(SpokeStatus::Offered)
        ->and(spk($site, 'Sump Pump Installation')->status)->toBe(SpokeStatus::Offered)
        ->and(spk($site, 'Why Is My Basement Wet?')->status)->toBe(SpokeStatus::Content)
        ->and(spk($site, 'Gutter Installation')->status)->toBe(SpokeStatus::Candidate); // connecting untouched
});

test('confirm is gated until every non-fringe candidate is decided', function () {
    $site = pruneSite();
    $pruner = app(Pruner::class);

    $blocked = $pruner->confirm($site);
    expect($blocked['confirmed'])->toBeFalse()
        ->and($blocked['pending'])->toBe(5)
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->toBeNull();

    // decide everything (fringe is excluded from the gate)
    $pruner->acceptCore($site); // pillar + 2 core
    $pruner->apply($site, ['Gutter Installation' => 'capture', 'Curtain Drain' => 'skip']);

    $ok = $pruner->confirm($site);
    expect($ok['confirmed'])->toBeTrue()
        ->and($pruner->plan($site)->confirmed)->toBeTrue();
});

test('apply reports unmatched keys and bad outcomes', function () {
    $site = pruneSite();

    $result = app(Pruner::class)->apply($site, ['Nonexistent Spoke' => 'offer', 'Curtain Drain' => 'bogus']);

    expect($result['applied'])->toBe(0)
        ->and($result['unmatched'])->toContain('Nonexistent Spoke')
        ->and(collect($result['unmatched'])->contains(fn ($u) => str_contains($u, 'bad outcome')))->toBeTrue();
});
