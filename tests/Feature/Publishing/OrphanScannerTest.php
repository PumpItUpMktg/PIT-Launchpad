<?php

use App\Enums\ContentKind;
use App\Enums\OrphanType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Redirect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\OrphanScanner;

function scanPage(Site $site, array $extra = []): Content
{
    return Content::withoutGlobalScope(SiteScope::class)->create(array_merge([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Service,
        'title' => 'A page',
        'slug' => 'a-page',
        'version' => 1,
    ], $extra));
}

function types(array $findings): array
{
    return array_map(fn ($f) => $f->type, $findings);
}

it('reports nothing for a clean site', function () {
    $site = Site::factory()->create();
    scanPage($site, ['title' => 'Live', 'slug' => 'live', 'published_at' => now()]);

    expect(app(OrphanScanner::class)->scan($site))->toBe([]);
});

it('flags a live page whose parent hub was deleted', function () {
    $site = Site::factory()->create();
    $hub = scanPage($site, ['page_type' => PageType::Hub, 'title' => 'Drain Services', 'slug' => 'drain-services']);
    $child = scanPage($site, ['title' => 'Drain Cleaning', 'slug' => 'drain-services/drain-cleaning', 'parent_content_id' => $hub->id]);

    $hub->delete(); // soft-delete the parent

    $findings = app(OrphanScanner::class)->scan($site);
    expect(types($findings))->toBe([OrphanType::OrphanedChild])
        ->and($findings[0]->contentId)->toBe($child->id)
        ->and($findings[0]->url)->toBe('/drain-services/drain-cleaning');
});

it('flags a page deleted here but still carrying a wp_post_id (stranded live)', function () {
    $site = Site::factory()->create();
    $p = scanPage($site, ['title' => 'Gone', 'slug' => 'gone', 'published_at' => now(), 'wp_post_id' => 42]);
    $p->delete();

    $findings = app(OrphanScanner::class)->scan($site);
    expect(types($findings))->toBe([OrphanType::StrandedLive])
        ->and($findings[0]->url)->toBe('/gone');
});

it('flags a retired published URL with no redirect, but not one that is covered', function () {
    $site = Site::factory()->create();

    // Retired (deleted, taken off WP so wp_post_id null) with no redirect → needs a 301.
    $bare = scanPage($site, ['title' => 'Bare', 'slug' => 'bare', 'published_at' => now(), 'wp_post_id' => null]);
    $bare->delete();

    // Retired but a redirect already covers it → not reported.
    $covered = scanPage($site, ['title' => 'Covered', 'slug' => 'covered', 'published_at' => now(), 'wp_post_id' => null]);
    $covered->delete();
    Redirect::withoutGlobalScope(SiteScope::class)->create([
        'site_id' => $site->id, 'from_url' => '/covered', 'to_url' => '/', 'code' => 301, 'status' => 'active',
    ]);

    $findings = app(OrphanScanner::class)->scan($site);

    expect(types($findings))->toBe([OrphanType::MissingRedirect])
        ->and($findings[0]->url)->toBe('/bare');
});

it('does not flag a never-published deleted draft (no live URL to strand)', function () {
    $site = Site::factory()->create();
    $draft = scanPage($site, ['title' => 'Draft', 'slug' => 'draft', 'published_at' => null, 'wp_post_id' => null]);
    $draft->delete();

    expect(app(OrphanScanner::class)->scan($site))->toBe([]);
});
