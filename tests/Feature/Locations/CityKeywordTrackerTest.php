<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\MarketTier;
use App\Enums\PageType;
use App\Enums\ServiceSiloRole;
use App\Locations\CityKeywordTracker;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Support\Str;

function cktLocationPage(Site $site, Market $market, array $extra = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page->value,
        'page_type' => PageType::Location->value,
        'status' => ContentStatus::Published->value,
        'market_id' => $market->id,
        'title' => $market->name,
        'slug' => Str::slug($market->name),
    ], $extra));
}

it('assigns "{head} {city}" keywords to a priority-city location page, pinned to that page + market', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.com']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $norristown = Market::factory()->create(['site_id' => $site->id, 'name' => 'Norristown', 'tier' => MarketTier::Priority->value]);
    $page = cktLocationPage($site, $norristown);

    $result = app(CityKeywordTracker::class)->assign($site);

    expect($result['cities'])->toBe(1)
        ->and($result['created'])->toBe(2)
        ->and($result['keywords'])->toBe(['Sump Pump Repair Norristown', 'Sump Pump Repair service Norristown']);

    $kws = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
    expect($kws)->toHaveCount(2)
        ->and($kws->pluck('source')->unique()->all())->toBe([KeywordSource::Local])
        ->and($kws->pluck('status')->unique()->all())->toBe(['scored'])
        ->and($kws->pluck('target_content_id')->unique()->all())->toBe([$page->id])
        ->and($kws->pluck('market_id')->unique()->all())->toBe([$norristown->id]);

    // The page's headline keyword points at "{head} {city}" so the live card shows its rank.
    $primary = $kws->firstWhere('query', 'Sump Pump Repair Norristown');
    expect($page->fresh()->target_keyword_id)->toBe($primary->id);
});

it('ignores coverage-tier cities and pages without a priority market', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.com']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Repair', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $coverage = Market::factory()->create(['site_id' => $site->id, 'name' => 'Faraway', 'tier' => MarketTier::Coverage->value]);
    cktLocationPage($site, $coverage);

    $result = app(CityKeywordTracker::class)->assign($site);

    expect($result['cities'])->toBe(0)
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('prefers the page\'s primary service as the head term, and is idempotent', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.com']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Waterproofing', 'silo_role' => ServiceSiloRole::Pillar->value]);
    $primaryService = Service::factory()->create(['site_id' => $site->id, 'name' => 'Basement Waterproofing', 'silo_role' => ServiceSiloRole::Supporting->value]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Trenton', 'tier' => MarketTier::Priority->value]);
    cktLocationPage($site, $market, ['primary_service_id' => $primaryService->id]);

    $tracker = app(CityKeywordTracker::class);
    $first = $tracker->assign($site);
    $second = $tracker->assign($site);

    expect($first['keywords'])->toBe(['Basement Waterproofing Trenton', 'Basement Waterproofing service Trenton'])
        ->and($first['created'])->toBe(2)
        ->and($second['created'])->toBe(0) // idempotent — updates in place, no duplicates
        ->and(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);
});
