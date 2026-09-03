<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkPlanStatus;
use App\Enums\LinkSourceType;
use App\Enums\PageType;
use App\Enums\StandardPageType;
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
use App\Operate\LinkPlanActions;
use App\Publishing\Links\LinkPlanBuilder;
use App\Support\CurrentSite;
use App\Support\TownName;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

/** A fully-wired market: site, a Location, its published landing page, and coverage for two large towns. */
function lpMarket(): array
{
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->for($site)->create(['name' => 'Newark']);
    $landing = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $market->id, 'status' => ContentStatus::Published, 'title' => 'Newark', 'slug' => 'newark', 'wp_post_id' => 1,
    ]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'B1', 'name' => 'Big', 'size_tier' => 'large', 'population' => 35000, 'lat' => 40.70, 'lng' => -74.10, 'source_location_ids' => [$market->id], 'source' => 'county']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'M1', 'name' => 'Mid', 'size_tier' => 'large', 'population' => 32000, 'lat' => 40.71, 'lng' => -74.11, 'source_location_ids' => [$market->id], 'source' => 'county']);

    return [$site, $market, $landing];
}

afterEach(fn () => CurrentSite::clear());

it('proposes inbound links from the five sources, deduped by strongest type', function () {
    [$site, $market, $landing] = lpMarket();
    lpIndex($site, $landing); // the landing must be indexed for the Job/review upgrade
    $big = lpTown($site, 'Big', $market->id);
    $mid = lpTown($site, 'Mid', $market->id);
    lpIndex($site, $big);
    lpIndex($site, $mid); // both indexed → mutual mesh neighbours (centroids ~1 mile apart)

    // Blog post tagged with Big; a published review in Big (→ Job/review upgrade for Big).
    $post = Content::factory()->post()->published()->create(['site_id' => $site->id, 'body' => 'A story about Big.', 'slug' => 'a-story', 'wp_post_id' => 7]);
    ContentTown::create(['site_id' => $site->id, 'content_id' => $post->id, 'town' => TownName::key('Big'), 'town_display' => 'Big']);
    Review::factory()->for($site)->published()->create(['town' => 'Big']);
    // Areas We Serve directory page.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe, 'status' => ContentStatus::Published, 'slug' => 'areas-we-serve', 'wp_post_id' => 9]);

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');
    $items = $plan->items;
    $type = fn (string $target, LinkSourceType $t) => $items->firstWhere(fn ($i) => $i->target_content_id === $target && $i->source_type === $t);

    // Big has local proof + indexed landing → its landing link is the strongest (Job/review), not Market.
    expect($type($big->id, LinkSourceType::JobReview))->not->toBeNull()
        ->and($type($big->id, LinkSourceType::Market))->toBeNull()
        // Mid has no proof → plain Market landing link.
        ->and($type($mid->id, LinkSourceType::Market))->not->toBeNull()
        // Mesh: the indexed neighbour town links across.
        ->and($type($big->id, LinkSourceType::Mesh))->not->toBeNull()
        ->and($type($mid->id, LinkSourceType::Mesh))->not->toBeNull()
        // Blog: the post that names Big.
        ->and($type($big->id, LinkSourceType::Blog)->source_content_id)->toBe((string) $post->id)
        // Areas: the directory links each town.
        ->and($type($big->id, LinkSourceType::Areas))->not->toBeNull()
        ->and($type($mid->id, LinkSourceType::Areas))->not->toBeNull();
});

it('caps the links added to any one source page per plan', function () {
    config(['launchpad.link_plan.max_links_per_source' => 2]);
    [$site, $market] = lpMarket();
    // Five towns of the tier — the single Areas page would otherwise gain 5 links.
    foreach (['A', 'B', 'C', 'D', 'E'] as $i => $n) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => "T$i", 'name' => $n, 'size_tier' => 'large', 'population' => 31000, 'lat' => 40.7, 'lng' => -74.1, 'source_location_ids' => [$market->id], 'source' => 'county']);
        lpTown($site, $n, $market->id);
    }
    $areas = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe, 'status' => ContentStatus::Published, 'slug' => 'areas', 'wp_post_id' => 9]);

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'large');

    expect($plan->items->where('source_content_id', $areas->id)->count())->toBe(2); // capped, not 5
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
    // Big is non-orphan (the market landing grids to it) → its URL was submitted to IndexNow.
    expect($submitted)->toContain(LP_HOME.'/big')
        ->and($result['orphaned'])->toBe([])
        ->and($plan->fresh()->status)->toBe(LinkPlanStatus::Applied)
        ->and($plan->items()->where('status', LinkPlanItemStatus::Applied->value)->count())->toBeGreaterThan(0);
});

it('never submits a zero-inbound town to IndexNow (the no-orphan guard)', function () {
    Queue::fake();
    $indexNow = Mockery::mock(IndexNowSubmitter::class);
    $indexNow->shouldNotReceive('submit'); // no non-orphan town → nothing submitted
    app()->instance(IndexNowSubmitter::class, $indexNow);

    // A market with NO landing → no grid edge to its town. The town becomes a plan target only via the Areas
    // page (a spine-republish item), whose edge isn't in the graph until the queued republish runs — so right
    // after apply the town still has zero inbound and must NOT be submitted.
    $site = Site::factory()->create(['domain_url' => LP_HOME]);
    CurrentSite::set($site->id);
    $market = Location::factory()->for($site)->create(['name' => 'Nowhere']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'O1', 'name' => 'Orphanville', 'size_tier' => 'small', 'population' => 4000, 'lat' => 40.7, 'lng' => -74.1, 'source_location_ids' => [$market->id], 'source' => 'county']);
    $orphan = lpTown($site, 'Orphanville', $market->id);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe, 'status' => ContentStatus::Published, 'slug' => 'areas', 'wp_post_id' => 9]);

    $plan = app(LinkPlanBuilder::class)->propose($site, $market, 'small');
    app(LinkPlanActions::class)->approveAll($plan);
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

it('the plan-links command proposes and reports', function () {
    [$site, $market] = lpMarket();
    lpTown($site, 'Big', $market->id);

    $this->artisan('launchpad:plan-links', ['site' => $site->id, '--market' => $market->id, '--tier' => 'large'])
        ->expectsOutputToContain('Newark')
        ->expectsOutputToContain('proposed link')
        ->assertSuccessful();

    expect(LinkPlan::where('site_id', $site->id)->exists())->toBeTrue();
});
