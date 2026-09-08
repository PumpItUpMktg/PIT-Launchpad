<?php

use App\Analytics\Gsc\Grain;
use App\Enums\CitationPresence;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RankingState;
use App\Models\CitationStatus;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Review;
use App\Models\Site;
use App\Operate\MarketCard;
use App\Operate\MarketCards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const MC_HOME = 'https://mkt.example';

function mcGsc(Site $site, string $slug, int $impressions, int $clicks, float $position): void
{
    $date = now()->subDays(2)->toDateString();
    $url = MC_HOME.'/'.trim($slug, '/').'/';
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, $date, $url]),
        'date' => $date,
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => 0,
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, MarketCard> cards keyed by location id */
function mcCards(Site $site): array
{
    return collect(app(MarketCards::class)->forSite($site))->keyBy('id')->all();
}

it('builds one card per Location, rolls up GSC, tiers, proof, GBP link and county mismatch', function () {
    $site = Site::factory()->create(['domain_url' => MC_HOME]);

    // A LIVE market with a hub page, a built town, coverage across two tiers, proof, and a county mismatch.
    $live = Location::factory()->for($site)->create([
        'name' => 'Hoboken',
        'publish_held' => false,
        'place_id' => 'ChIJlive123',
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Hoboken'],
            ['types' => ['administrative_area_level_1'], 'short_name' => 'NJ'],
        ],
        'home_county_geoid' => '42029',   // Chester PA — NOT in the served county below → the mismatch defect
        'county_geoids' => ['42077'],
    ]);

    // hub (the market page) + one built town, both published, both with GSC positions.
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => $live->id,
        'title' => 'Hoboken', 'slug' => 'hoboken-nj',
    ]);
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $live->id, 'primary_service_id' => null, 'title' => 'Big', 'slug' => 'hoboken-nj/big',
    ]);
    mcGsc($site, 'hoboken-nj', 100, 10, 2.0);       // hub → top 3
    mcGsc($site, 'hoboken-nj/big', 50, 5, 8.0);     // town → page one

    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4207701', 'name' => 'Big', 'size_tier' => 'major', 'population' => 60000, 'source_location_ids' => [$live->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4207702', 'name' => 'Mid', 'size_tier' => 'medium', 'population' => 20000, 'source_location_ids' => [$live->id]]);

    Review::factory()->published()->create(['site_id' => $site->id, 'location_id' => $live->id, 'rating' => 5]);
    Review::factory()->published()->create(['site_id' => $site->id, 'location_id' => $live->id, 'rating' => 5]);
    CitationStatus::factory()->presence(CitationPresence::PresentMatch)->create(['site_id' => $site->id, 'location_id' => $live->id]);

    // A HELD market — a served small town + a drafted (unpublished) town page.
    $held = Location::factory()->for($site)->create(['name' => 'Trenton', 'publish_held' => true]);
    Content::factory()->page()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'location_id' => null,
        'parent_location_id' => $held->id, 'primary_service_id' => null, 'title' => 'SmallTown', 'slug' => 'trenton-nj/smalltown',
        'status' => ContentStatus::NeedsReview,
    ]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4207703', 'name' => 'SmallTown', 'size_tier' => 'small', 'population' => 4000, 'source_location_ids' => [$held->id]]);

    $cards = mcCards($site);
    expect($cards)->toHaveCount(2);

    // ── The live market ──
    $a = $cards[(string) $live->id];
    expect($a->held)->toBeFalse()
        ->and($a->name)->toBe('Hoboken, NJ')
        ->and($a->impressions)->toBe(150)
        ->and($a->clicks)->toBe(15)
        ->and($a->rankingState)->toBe(RankingState::Ranked)
        ->and($a->rankTop3)->toBe(1)
        ->and($a->rankPageOne)->toBe(1)
        ->and($a->rankBeyond)->toBe(0)
        ->and($a->reviewsCount)->toBe(2)
        ->and($a->reviewsAvg)->toBe(5.0)
        ->and($a->citationsLive)->toBe(1)
        ->and($a->jobsCount)->toBeNull()               // jobs tie to JobCity, not Location → not_tracked
        ->and($a->sessions)->toBeNull()                // GA4 not connected → not_tracked (never a fabricated 0)
        ->and($a->gbpUrl)->toContain('place_id:ChIJlive123')
        ->and($a->countyMismatch)->not->toBeNull()
        ->and($a->marketPagePosition)->toBe(2)
        ->and($a->townsWithPages)->toBe(1)
        ->and($a->townsTotal)->toBe(2)
        ->and($a->gscFreshness)->not->toBeNull();

    $major = collect($a->sizeTiers)->firstWhere('tier', 'major');
    $medium = collect($a->sizeTiers)->firstWhere('tier', 'medium');
    expect($major)->toMatchArray(['built' => 1, 'served' => 1])
        ->and($medium)->toMatchArray(['built' => 0, 'served' => 1]);

    // ── The held market: no metric row, ranking not_tracked, tier counts + drafted count kept ──
    $b = $cards[(string) $held->id];
    expect($b->held)->toBeTrue()
        ->and($b->impressions)->toBeNull()
        ->and($b->clicks)->toBeNull()
        ->and($b->sessions)->toBeNull()
        ->and($b->rankingState)->toBe(RankingState::NotTracked)
        ->and($b->draftedPages)->toBe(1)
        ->and($b->gscFreshness)->toBeNull();

    $small = collect($b->sizeTiers)->firstWhere('tier', 'small');
    expect($small['served'])->toBe(1);
});

it('reads not_tracked for a market with no GSC, no proof, and no place_id', function () {
    $site = Site::factory()->create(['domain_url' => MC_HOME]);
    $bare = Location::factory()->for($site)->create(['name' => 'Empty', 'publish_held' => false, 'place_id' => null]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '4207799', 'name' => 'Nowhere', 'size_tier' => 'small', 'population' => 3000, 'source_location_ids' => [$bare->id]]);

    $card = mcCards($site)[(string) $bare->id];

    expect($card->impressions)->toBeNull()
        ->and($card->rankingState)->toBe(RankingState::NotTracked)
        ->and($card->reviewsCount)->toBeNull()
        ->and($card->citationsLive)->toBeNull()
        ->and($card->gbpUrl)->toBeNull()               // no place_id → no GBP link (not a broken one)
        ->and($card->marketPageUrl)->toBeNull()
        ->and($card->townsTotal)->toBe(1);
});
