<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Breadcrumbs\SiloIndexResolver;

/** A guided silo — the production shape on SPG: NO pillar_content_id, several sibling service pages. */
function guidedSilo(Site $s, string $name): Silo
{
    return Silo::factory()->create(['site_id' => $s->id, 'name' => $name, 'pillar_content_id' => null]);
}

function resolverSiloPage(Site $s, Silo $silo, string $title, string $slug, PageType $type = PageType::Service, ContentStatus $status = ContentStatus::Published): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'silo_id' => $silo->id, 'page_type' => $type,
        'title' => $title, 'slug' => $slug, 'status' => $status,
    ]);
}

it('picks the name-matching sibling, not the first published one, with no pillar (the production shape)', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Basement Waterproofing');
    // Insert the WRONG sibling first so an unordered ->first() would grab it (the actual bug).
    resolverSiloPage($site, $silo, 'Exterior Foundation Waterproofing', 'exterior-foundation-waterproofing');
    resolverSiloPage($site, $silo, 'Basement Waterproofing', 'basement-waterproofing');
    $leaf = resolverSiloPage($site, $silo, 'Crawl Space Encapsulation', 'crawl-space-encapsulation');

    // The crumb on the crawl-space page must link the silo INDEX (basement-waterproofing), not a sibling.
    expect(app(SiloIndexResolver::class)->topSlug($leaf))->toBe('basement-waterproofing');
});

it('matches the name against a nested slug tail', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Basement Waterproofing');
    resolverSiloPage($site, $silo, 'Basement Waterproofing', 'services/basement-waterproofing');
    $leaf = resolverSiloPage($site, $silo, 'Sump Pumps', 'services/sump-pumps');

    expect(app(SiloIndexResolver::class)->topSlug($leaf))->toBe('services/basement-waterproofing');
});

it('prefers a Hub as the structural index when one exists', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Waterproofing');
    $hub = resolverSiloPage($site, $silo, 'Waterproofing Services', 'waterproofing', PageType::Hub);
    $leaf = resolverSiloPage($site, $silo, 'Basement Waterproofing', 'basement-waterproofing');

    expect(app(SiloIndexResolver::class)->topContent($leaf)?->id)->toBe($hub->id);
});

it('uses the designated pillar when one is set and live', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Waterproofing');
    $pillar = resolverSiloPage($site, $silo, 'Waterproofing', 'the-pillar');
    $silo->update(['pillar_content_id' => $pillar->id]);
    $leaf = resolverSiloPage($site, $silo, 'Basement Waterproofing', 'basement-waterproofing');

    expect(app(SiloIndexResolver::class)->topSlug($leaf->fresh()))->toBe('the-pillar');
});

it('falls to similarity when no exact name match, above the confidence floor', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Basement Waterproofing');
    // No page titled exactly "Basement Waterproofing"; the closest is a near variant.
    resolverSiloPage($site, $silo, 'Basement Waterproofing Repair', 'basement-waterproofing-repair');
    $leaf = resolverSiloPage($site, $silo, 'Sump Pumps', 'sump-pumps');

    expect(app(SiloIndexResolver::class)->topSlug($leaf))->toBe('basement-waterproofing-repair');
});

it('emits NO index rather than a wrong one when nothing matches confidently', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Basement Waterproofing');
    // Siblings share the silo but none resembles its name — better no crumb than a coin-flip.
    resolverSiloPage($site, $silo, 'Gutter Guards', 'gutter-guards');
    $leaf = resolverSiloPage($site, $silo, 'Roof Repair', 'roof-repair');

    expect(app(SiloIndexResolver::class)->topSlug($leaf))->toBe('');
});

it('ignores unpublished siblings and never returns the page itself', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Basement Waterproofing');
    resolverSiloPage($site, $silo, 'Basement Waterproofing', 'basement-waterproofing', status: ContentStatus::Candidate); // not live
    $self = resolverSiloPage($site, $silo, 'Basement Waterproofing', 'self-page');

    // The only name match is unpublished, and self is excluded → no index resolves.
    expect(app(SiloIndexResolver::class)->topSlug($self))->toBe('');
});

it('drops rather than links wrong when the index page is pinned to a DIFFERENT silo (the linchpin)', function () {
    $site = Site::factory()->create();
    $silo = guidedSilo($site, 'Basement Waterproofing');
    $other = guidedSilo($site, 'Unrelated Cluster');
    // The page that IS the index by name carries a DIFFERENT silo_id → not in this silo's candidate set.
    resolverSiloPage($site, $other, 'Basement Waterproofing', 'basement-waterproofing');
    // The silo's own pages are unrelated siblings that don't resemble its name.
    resolverSiloPage($site, $silo, 'Exterior Foundation Waterproofing', 'exterior-foundation-waterproofing');
    $leaf = resolverSiloPage($site, $silo, 'Crawl Space Encapsulation', 'crawl-space-encapsulation');

    // The name match is scoped to silo_id, so a mis-pinned index is unreachable — the resolver drops the
    // crumb (Home → Leaf) instead of linking a sibling. Producing the CORRECT crumb needs the index re-pinned.
    expect(app(SiloIndexResolver::class)->topSlug($leaf))->toBe('');
});

it('returns empty for a page with no silo', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'page_type' => PageType::Service]);

    expect(app(SiloIndexResolver::class)->topSlug($page))->toBe('');
});
