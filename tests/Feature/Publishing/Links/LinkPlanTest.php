<?php

use App\Analytics\Gsc\Grain;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Enums\LinkSourceType;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Filament\Pages\Operate\OperateLinkPlans;
use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\LinkPlan;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Review;
use App\Models\Site;
use App\Models\User;
use App\Operate\LinkPlanActions;
use App\Publishing\Links\LinkPlanBuilder;
use App\Support\CurrentSite;
use App\Support\TownName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

const LP_HOME = 'https://spg.example';

function lpIndex(Site $site, Content $c): void
{
    $url = LP_HOME.'/'.ltrim((string) $c->slug, '/');
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $c->id, 'url' => $url, 'url_normalized' => $url, 'index_verdict' => 'PASS']);
}

function lpTown(Site $site, string $name, string $marketId, array $attrs = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $marketId, 'primary_service_id' => null,
        'title' => $name, 'slug' => Str::slug($name), 'status' => ContentStatus::Published,
        'slot_payload' => ['intro' => 'Serving the local area with dependable service every day.'],
        'wp_post_id' => 100,
    ], $attrs));
}

function lpCoverage(Site $site, string $name, string $tier, string $marketId, float $lat = 40.70, float $lng = -74.10): void
{
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'geo_id' => 'G'.Str::random(8), 'name' => $name, 'size_tier' => $tier,
        'population' => 33000, 'lat' => $lat, 'lng' => $lng, 'source_location_ids' => [$marketId], 'source' => 'county',
    ]);
}

/** Seed a GSC blended position for a town slug (so ranksTop3 can see it). */
function lpGscPos(Site $site, string $slug, float $position): void
{
    $date = now()->subDays(2)->toDateString();
    $url = LP_HOME.'/'.trim($slug, '/').'/';
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Grain::hash([$site->id, $date, $url]),
        'date' => $date, 'url' => $url, 'impressions' => 100, 'clicks' => 5, 'ctr' => 0, 'position' => $position,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A fully-wired market: site, a Location, its published landing page, and coverage for two large towns. */
function lpMarket(): array
{
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->released()->for($site)->create(['name' => 'Newark']); // publishing market → released (held-market IndexNow exclusion is covered separately)
    $landing = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $market->id, 'status' => ContentStatus::Published, 'title' => 'Newark', 'slug' => 'newark', 'wp_post_id' => 1,
    ]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'B1', 'name' => 'Big', 'size_tier' => 'large', 'population' => 35000, 'lat' => 40.70, 'lng' => -74.10, 'source_location_ids' => [$market->id], 'source' => 'county']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'M1', 'name' => 'Mid', 'size_tier' => 'large', 'population' => 32000, 'lat' => 40.71, 'lng' => -74.11, 'source_location_ids' => [$market->id], 'source' => 'county']);

    return [$site, $market, $landing];
}

afterEach(fn () => CurrentSite::clear());

it('proposes job/review, market, blog and DIRECTIONAL mesh — no areas, no reciprocals', function () {
    [$site, $market, $landing] = lpMarket();
    lpIndex($site, $landing); // indexed landing → eligible for the Job/review upgrade

    // Mid = an INDEXED neighbour (a mesh SOURCE, never a mesh target). Big = an UNINDEXED town (the starved
    // page mesh should feed). Centroids ~1 mile apart (lpMarket coverage 'Big'/'Mid').
    $mid = lpTown($site, 'Mid', $market->id);
    $big = lpTown($site, 'Big', $market->id);
    lpIndex($site, $mid); // big stays unindexed

    // Big: local proof (→ Job/review) + a blog mention.
    Review::factory()->for($site)->published()->create(['town' => 'Big']);
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'body' => 'A story about Big.', 'slug' => 'a-story', 'wp_post_id' => 7]);
    ContentTown::create(['site_id' => $site->id, 'content_id' => $post->id, 'town' => TownName::key('Big'), 'town_display' => 'Big']);

    // An Areas We Serve directory page — must NOT become a link source (its own directory block links towns).
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe, 'status' => ContentStatus::Published, 'slug' => 'areas-we-serve', 'wp_post_id' => 9]);

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    $items = $plan->items;
    $type = fn (string $target, LinkSourceType $t) => $items->firstWhere(fn ($i) => $i->target_content_id === $target && $i->source_type === $t);

    expect($type($big->id, LinkSourceType::JobReview))->not->toBeNull()      // proof + indexed landing → upgraded
        ->and($type($big->id, LinkSourceType::Market))->toBeNull()          // not plain Market
        ->and($type($mid->id, LinkSourceType::Market))->not->toBeNull()     // Mid: no proof → plain Market
        ->and($type($big->id, LinkSourceType::Blog)->source_content_id)->toBe((string) $post->id)
        ->and($type($big->id, LinkSourceType::Mesh))->not->toBeNull()       // indexed Mid → unindexed Big
        ->and($type($mid->id, LinkSourceType::Mesh))->toBeNull()            // Mid is indexed → never a mesh target: no reciprocal
        ->and($items->where('source_type', LinkSourceType::Areas)->count())->toBe(0); // Areas is no longer a source
});

it('caps the links added to any one source page per plan', function () {
    config(['launchpad.link_plan.max_links_per_source' => 2]);
    [$site, $market, $landing] = lpMarket();
    // Five towns of the tier — the market landing spine would otherwise gain a Market link to each.
    foreach (['A', 'B', 'C', 'D', 'E'] as $n) {
        lpCoverage($site, $n, 'large', $market->id);
        lpTown($site, $n, $market->id);
    }

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');

    expect($plan->items->where('source_content_id', $landing->id)->count())->toBe(2); // capped, not 5+
});

it('commits an approved plan: writes links, republishes sources, submits only non-orphan towns to IndexNow', function () {
    Queue::fake();
    $indexNow = Mockery::mock(IndexNowSubmitter::class);
    $submitted = [];
    $indexNow->shouldReceive('submit')->andReturnUsing(function (Site $s, array $urls) use (&$submitted) {
        $submitted = $urls;

        return ['ok' => true, 'submitted' => count($urls), 'status' => 200, 'reason' => null];
    });
    app()->instance(IndexNowSubmitter::class, $indexNow);

    [$site, $market] = lpMarket();
    $big = lpTown($site, 'Big', $market->id);
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'body' => 'Story.', 'slug' => 'story', 'wp_post_id' => 7]);
    ContentTown::create(['site_id' => $site->id, 'content_id' => $post->id, 'town' => TownName::key('Big'), 'town_display' => 'Big']);

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    app(LinkPlanActions::class)->approveAll($plan);
    $result = app(LinkPlanActions::class)->apply($plan->fresh(['items']));

    // The blog post gained an inbound anchor to Big and was queued for re-publish.
    expect($post->fresh()->body)->toContain('/big');
    Queue::assertPushed(PublishContent::class, fn (PublishContent $j) => $j->contentId === (string) $post->id);
    // Big is non-orphan (the market landing grids to it) → its canonical URL was submitted to IndexNow
    // (trailing-slash form via PublicUrl, not the slug-only variant that 301-redirects).
    expect($submitted)->toContain(LP_HOME.'/big/')
        ->and($result['orphaned'])->toBe([])
        ->and($plan->fresh()->status)->toBe(LinkPlanStatus::Applied)
        ->and($plan->items()->where('status', LinkPlanItemStatus::Applied->value)->count())->toBeGreaterThan(0);
});

it('does not announce a held location\'s town URLs to IndexNow, even when non-orphan', function () {
    Queue::fake();
    $indexNow = Mockery::mock(IndexNowSubmitter::class);
    $submitted = [];
    $indexNow->shouldReceive('submit')->andReturnUsing(function (Site $s, array $urls) use (&$submitted) {
        $submitted = $urls;

        return ['ok' => true, 'submitted' => count($urls), 'status' => 200, 'reason' => null];
    });
    app()->instance(IndexNowSubmitter::class, $indexNow);

    // Same wiring as the commit test, but the market (Location) is HELD (the factory default). Big is
    // non-orphan (the market landing grids to it) yet must NOT be announced — its page hasn't shipped.
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']); // held
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $market->id, 'status' => ContentStatus::Published, 'title' => 'Newark', 'slug' => 'newark', 'wp_post_id' => 1,
    ]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'B1', 'name' => 'Big', 'size_tier' => 'large', 'population' => 35000, 'lat' => 40.70, 'lng' => -74.10, 'source_location_ids' => [$market->id], 'source' => 'county']);
    lpTown($site, 'Big', $market->id);
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'body' => 'Story.', 'slug' => 'story', 'wp_post_id' => 7]);
    ContentTown::create(['site_id' => $site->id, 'content_id' => $post->id, 'town' => TownName::key('Big'), 'town_display' => 'Big']);

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    app(LinkPlanActions::class)->approveAll($plan);
    app(LinkPlanActions::class)->apply($plan->fresh(['items']));

    expect($submitted)->not->toContain(LP_HOME.'/big/'); // held market → its town URL is never announced
});

it('never submits a zero-inbound town to IndexNow (the no-orphan guard)', function () {
    Queue::fake();
    $indexNow = Mockery::mock(IndexNowSubmitter::class);
    $indexNow->shouldNotReceive('submit'); // no non-orphan town → nothing submitted
    app()->instance(IndexNowSubmitter::class, $indexNow);

    // A town with NO landing and no inbound. We build the plan DIRECTLY with a null-anchor spine item to it
    // (a whole-page republish, not an anchor injection), so at apply time the town still has zero inbound in
    // the graph — the guard must not announce it. (Areas is no longer a builder source, so the item is made
    // by hand; the guard under test lives in LinkPlanCommitter, unchanged by the mesh constraint.)
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->for($site)->create(['name' => 'Nowhere']);
    $orphan = lpTown($site, 'Orphanville', $market->id);
    $spine = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe, 'status' => ContentStatus::Published, 'slug' => 'areas', 'wp_post_id' => 9]);

    $plan = LinkPlan::create(['site_id' => $site->id, 'market_location_id' => $market->id, 'tier' => 'small', 'status' => LinkPlanStatus::Proposed]);
    $plan->items()->create(['site_id' => $site->id, 'source_content_id' => $spine->id, 'target_content_id' => $orphan->id, 'source_type' => LinkSourceType::Areas, 'anchor_term' => null, 'status' => LinkPlanItemStatus::Proposed]);

    app(LinkPlanActions::class)->approveAll($plan->fresh(['items']));
    $result = app(LinkPlanActions::class)->apply($plan->fresh(['items']));

    expect($result['orphaned'])->toContain((string) $orphan->id)
        ->and($result['submitted'])->toBe([]);
});

it('actions gate the plan: reject settles an item, apply is idempotent once applied', function () {
    Queue::fake();
    $indexNow = Mockery::mock(IndexNowSubmitter::class);
    $indexNow->shouldReceive('submit')->andReturn(['ok' => true, 'submitted' => 1, 'status' => 200, 'reason' => null]);
    app()->instance(IndexNowSubmitter::class, $indexNow);

    [$site, $market] = lpMarket();
    lpTown($site, 'Big', $market->id);
    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    $actions = app(LinkPlanActions::class);

    $item = $plan->items->first();
    $actions->reject($item);
    expect($item->fresh()->status)->toBe(LinkPlanItemStatus::Rejected);

    $actions->approveAll($plan);
    $actions->apply($plan->fresh());
    expect($plan->fresh()->status)->toBe(LinkPlanStatus::Applied);

    // Re-applying an applied plan is a no-op.
    expect($actions->apply($plan->fresh()))->toBe(['applied' => 0, 'republished' => 0, 'submitted' => [], 'orphaned' => []]);
});

it('the link-plans page renders and proposes for an operator', function () {
    $this->actingAs(User::factory()->create());
    [$site, $market] = lpMarket();
    lpTown($site, 'Big', $market->id);

    Livewire::test(OperateLinkPlans::class)
        ->set('siteId', $site->id)
        ->set('proposeMarketId', $market->id)
        ->set('proposeTier', 'large')
        ->call('propose')
        ->assertOk();

    expect(LinkPlan::where('site_id', $site->id)->exists())->toBeTrue();
});

it('mesh links only the N nearest indexed neighbours to a starved unindexed town', function () {
    config(['launchpad.link_plan.mesh_nearest' => 3, 'launchpad.link_plan.max_inbound_per_target' => 10]);
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->released()->for($site)->create(['name' => 'Metro']);

    // Target: unindexed, no landing → mesh is its only possible source.
    $target = lpTown($site, 'Target', $market->id);
    lpCoverage($site, 'Target', 'large', $market->id, 40.700, -74.00);

    // Five INDEXED neighbours at increasing distance from the target.
    $near = [];
    foreach ([['N1', 40.701], ['N2', 40.702], ['N3', 40.703], ['N4', 40.760], ['N5', 40.820]] as [$n, $lat]) {
        $t = lpTown($site, $n, $market->id);
        lpIndex($site, $t);
        lpCoverage($site, $n, 'large', $market->id, $lat, -74.00);
        $near[$n] = (string) $t->id;
    }

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    $mesh = $plan->items->where('target_content_id', (string) $target->id)->where('source_type', LinkSourceType::Mesh);

    expect($mesh->count())->toBe(3) // the 3 nearest, not all 5 within the radius
        ->and($mesh->pluck('source_content_id')->all())->toEqualCanonicalizing([$near['N1'], $near['N2'], $near['N3']]);
});

it('mesh skips a top-3 target and one already at the inbound floor, but still feeds a starved town', function () {
    config(['launchpad.link_plan.mesh_nearest' => 3, 'launchpad.link_plan.max_inbound_per_target' => 3]);
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->released()->for($site)->create(['name' => 'Metro']);

    // One indexed neighbour source, near every target below.
    $src = lpTown($site, 'Src', $market->id);
    lpIndex($site, $src);
    lpCoverage($site, 'Src', 'large', $market->id, 40.700, -74.00);

    // Starved unindexed town → mesh feeds it.
    $starved = lpTown($site, 'Starved', $market->id);
    lpCoverage($site, 'Starved', 'large', $market->id, 40.701, -74.00);

    // Top-3 unindexed town (GSC position 2) → skipped despite being near and unindexed.
    $ranked = lpTown($site, 'Ranked', $market->id);
    lpCoverage($site, 'Ranked', 'large', $market->id, 40.702, -74.00);
    lpGscPos($site, 'ranked', 2.0);

    // At-the-floor unindexed town: three blog posts tag it → already 3 inbound → no mesh.
    $full = lpTown($site, 'Full', $market->id);
    lpCoverage($site, 'Full', 'large', $market->id, 40.703, -74.00);
    foreach (['p1', 'p2', 'p3'] as $slug) {
        $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'body' => 'x', 'slug' => $slug, 'wp_post_id' => 1]);
        ContentTown::create(['site_id' => $site->id, 'content_id' => $post->id, 'town' => TownName::key('Full'), 'town_display' => 'Full']);
    }

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    $mesh = fn (Content $t): int => $plan->items->where('target_content_id', (string) $t->id)->where('source_type', LinkSourceType::Mesh)->count();

    expect($mesh($starved))->toBeGreaterThanOrEqual(1) // starved unindexed → fed
        ->and($mesh($ranked))->toBe(0)                 // top-3 → skipped
        ->and($mesh($full))->toBe(0);                  // already at the inbound floor → skipped
});

it('the plan-links command proposes and reports', function () {
    [$site, $market] = lpMarket();
    lpTown($site, 'Big', $market->id);

    $this->artisan('launchpad:plan-links', ['site' => $site->id, '--market' => $market->id, '--tier' => 'large'])
        ->expectsOutputToContain('Newark')
        ->expectsOutputToContain('proposed link')
        ->assertSuccessful();

    expect(LinkPlan::where('site_id', $site->id)->exists())->toBeTrue();
});
