<?php

use App\Enums\JobStatus;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use Database\Seeders\RealisticFixtureSeeder;

test('realistic fixture seeder produces the production shape', function () {
    $this->seed(RealisticFixtureSeeder::class);

    $markets = Market::withoutGlobalScope(SiteScope::class)->get();
    expect($markets)->toHaveCount(12);
    expect($markets->where('tier', MarketTier::Priority))->toHaveCount(4);
    expect($markets->where('on_hold', true)->pluck('name')->all())->toBe(['Fallston']);

    $pages = Content::withoutGlobalScope(SiteScope::class)->where('kind', 'page');
    expect((clone $pages)->where('page_type', PageType::Location->value)->count())->toBe(180);
    expect((clone $pages)->where('page_type', PageType::Service->value)->count())->toBe(47);

    $jobs = Job::withoutGlobalScope(SiteScope::class)->get();
    expect($jobs->where('status', JobStatus::Review))->toHaveCount(7);
    expect($jobs->where('status', JobStatus::Published))->toHaveCount(24);

    // Both tier AXES are seeded: market MarketTier (Priority/Coverage, above) AND coverage_areas.size_tier
    // (major/large/medium/small) — the tier-progression / gate axis. It must span multiple tiers, not be flat.
    $sizeTiers = CoverageArea::withoutGlobalScope(SiteScope::class)->pluck('size_tier')->filter()->unique();
    expect($sizeTiers->count())->toBeGreaterThanOrEqual(3)
        ->and($sizeTiers)->toContain('small')->toContain('major');
});
