<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\LinkPlanItemStatus;
use App\Enums\LinkSourceType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Keyword;
use App\Models\LinkPlanItem;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Links\StrengthenPlanner;
use App\Support\CurrentSite;
use App\Support\PublicUrl;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function strengthenPage(Site $site, string $slug, array $attributes = []): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id,
        'slug' => $slug,
        'title' => ucfirst(str_replace('-', ' ', $slug)),
        'status' => ContentStatus::Published,
    ], $attributes));
}

function strengthenSearch(Site $site, Content $page, float $position, int $impressions = 400): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Str::random(32),
        'date' => now()->subDays(3)->toDateString(),
        'url' => (string) PublicUrl::forContent($site->domain_url, $page),
        'impressions' => $impressions,
        'clicks' => 2,
        'position' => $position,
    ]);
}

function strengthenTarget(Site $site, Content $page, int $volume, ?float $opportunity = null): void
{
    $keyword = Keyword::factory()->create([
        'site_id' => $site->id, 'volume' => $volume, 'opportunity_score' => $opportunity,
    ]);
    $page->forceFill(['target_keyword_id' => $keyword->id])->save();
}

/**
 * A hub with one struggling TOWN page at #18 and one strong sibling town, both published and earning.
 *
 * Town pages are the case that matters: InternalLinkGraph derives no silo edges for them, so unlike a
 * service page they are not already linked to their siblings by construction.
 */
function strikingDistanceSite(float $position = 18.0, int $volume = 300): array
{
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    $hub = strengthenPage($site, 'bedminster-nj', [
        'silo_id' => $silo->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub,
    ]);

    $target = strengthenPage($site, 'jefferson-nj', [
        'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_content_id' => $hub->id,
    ]);
    strengthenSearch($site, $target, $position, 200);
    strengthenTarget($site, $target, $volume, 0.74);

    $source = strengthenPage($site, 'peapack-nj', [
        'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_content_id' => $hub->id,
    ]);
    strengthenSearch($site, $source, 4.0, 9000);

    return [$site, $target, $source];
}

it('plans links for a page in striking distance that nothing links to', function () {
    [$site, $target, $source] = strikingDistanceSite();

    $rows = app(StrengthenPlanner::class)->preview($site);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['target'])->toBe((string) $target->id)
        ->and($rows[0]['position'])->toBe(18.0)
        ->and($rows[0]['volume'])->toBe(300)
        ->and($rows[0]['sources'])->toHaveCount(1)
        ->and($rows[0]['sources'][0]['id'])->toBe((string) $source->id);
});

it('leaves a page that is already winning alone', function () {
    // Position 2: the existing top-3 skip, drawn wider. A link here helps nothing.
    [$site] = strikingDistanceSite(position: 2.0);

    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('leaves a page too far back to be an internal-link problem', function () {
    [$site] = strikingDistanceSite(position: 45.0);

    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('refuses to spend links on a page nobody searches for', function () {
    // 18 → 8 on a term with three searches a month changes nothing, and the equity is not free.
    [$site] = strikingDistanceSite(volume: 3);

    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('will not source from a page Google never shows', function () {
    $site = Site::factory()->create();
    $hub = strengthenPage($site, 'bedminster-nj', ['kind' => ContentKind::Page, 'page_type' => PageType::Hub]);

    $target = strengthenPage($site, 'jefferson-nj', [
        'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_content_id' => $hub->id,
    ]);
    strengthenSearch($site, $target, 18.0);
    strengthenTarget($site, $target, 300);

    // A sibling under the same hub, but earning nothing — a link from it arrives on no crawl path.
    strengthenPage($site, 'invisible-sibling', [
        'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_content_id' => $hub->id,
    ]);

    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('will not source from an unrelated part of the site', function () {
    $site = Site::factory()->create();
    $hub = strengthenPage($site, 'bedminster-nj', ['kind' => ContentKind::Page, 'page_type' => PageType::Hub]);

    $target = strengthenPage($site, 'jefferson-nj', [
        'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'parent_content_id' => $hub->id,
    ]);
    strengthenSearch($site, $target, 18.0);
    strengthenTarget($site, $target, 300);

    // Strong, published, earning — and under a different hub in a different silo.
    $stranger = strengthenPage($site, 'unrelated-topic', [
        'silo_id' => Silo::factory()->create(['site_id' => $site->id])->id,
    ]);
    strengthenSearch($site, $stranger, 3.0, 9000);

    // Being on the same site is not relevance — the judgement that holds the mesh to nearest neighbours.
    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('persists a proposal that reaches nothing live until it is approved', function () {
    [$site, $target, $source] = strikingDistanceSite();

    $plan = app(StrengthenPlanner::class)->propose($site);

    expect($plan)->not->toBeNull();
    $item = LinkPlanItem::withoutGlobalScopes()->where('link_plan_id', $plan->id)->sole();

    expect($item->source_content_id)->toBe((string) $source->id)
        ->and($item->target_content_id)->toBe((string) $target->id)
        ->and($item->source_type)->toBe(LinkSourceType::Strengthen)
        ->and($item->status)->toBe(LinkPlanItemStatus::Proposed)
        ->and($item->applied_at)->toBeNull();
});

it('prints the evidence and stays read-only without --propose', function () {
    [$site] = strikingDistanceSite();
    $site->forceFill(['brand_name' => 'Sump Pump Gurus'])->save();

    $this->artisan('launchpad:plan-strengthen-links', ['--site' => $site->id])
        ->expectsOutputToContain('striking distance')
        ->expectsOutputToContain('Re-run with --propose')
        ->assertSuccessful();

    expect(LinkPlanItem::withoutGlobalScopes()->count())->toBe(0);
});

it('says plainly when nothing qualifies', function () {
    $site = Site::factory()->create(['brand_name' => 'Quiet Site']);

    $this->artisan('launchpad:plan-strengthen-links', ['--site' => $site->id])
        ->expectsOutputToContain('Nothing qualifies.')
        ->assertSuccessful();
});

it('refuses to guess which site', function () {
    $this->artisan('launchpad:plan-strengthen-links')->assertFailed();
});

it('never proposes a link the site already renders', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);

    // Two service pages in one silo: InternalLinkGraph derives the sibling grid as a real edge, so the
    // link already exists on the page. Proposing it again would be noise an operator has to reject.
    $target = strengthenPage($site, 'sump-pump-repair', [
        'silo_id' => $silo->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
    ]);
    strengthenSearch($site, $target, 18.0);
    strengthenTarget($site, $target, 300);

    $sibling = strengthenPage($site, 'sump-pump-replacement', [
        'silo_id' => $silo->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
    ]);
    strengthenSearch($site, $sibling, 4.0, 9000);

    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('will not build a reciprocal pair', function () {
    [$site, $target, $source] = strikingDistanceSite();

    // The struggling page already links out to the strong one; linking back would make A↔B, which is the
    // shape the constrained mesh was rewritten to avoid.
    $target->forceFill([
        'body' => '<p>See our <a href="'.PublicUrl::forContent($site->domain_url, $source).'">Peapack</a> page.</p>',
    ])->save();

    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});

it('counts the links a page already has against its ceiling', function () {
    // The hub already links down to the town through the derived location grid, so that link occupies
    // one of the target's slots before anything is proposed. A ceiling that only counts NEW links would
    // quietly double a page's inbound every time this ran.
    config(['launchpad.link_plan.strengthen.max_inbound_per_target' => 2]);
    [$site] = strikingDistanceSite();

    $rows = app(StrengthenPlanner::class)->preview($site);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['existing_inbound'])->toBe(1)
        ->and($rows[0]['sources'])->toHaveCount(1);

    // Drop the ceiling to what the page already carries and there is no room left to propose into.
    config(['launchpad.link_plan.strengthen.max_inbound_per_target' => 1]);
    expect(app(StrengthenPlanner::class)->preview($site))->toBe([]);
});
