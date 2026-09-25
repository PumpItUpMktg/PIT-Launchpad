<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\ContentTown;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Blocks\BlockContentAssembler;
use App\Publishing\Blocks\BlogFeeds;
use App\Publishing\Chrome\SiteProfileAssembler;
use App\Publishing\Links\InternalLinkGraph;
use App\Publishing\MetaBlobAssembler;

function blPost(Site $site, ?Silo $silo, string $title, string $slug, string $publishedAt, string $status = 'published'): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $silo?->id, 'matched_silo_id' => $silo?->id, 'status' => $status,
        'title' => $title, 'slug' => $slug, 'published_at' => $publishedAt, 'wp_post_id' => 1, 'body' => '<p>Article body about '.$title.'.</p>',
    ]);
}

function blBlogPage(Site $site): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'status' => ContentStatus::Published,
        'standard_type' => StandardPageType::Blog->value, 'slug' => 'blog', 'title' => 'Blog', 'wp_post_id' => 2,
        'slot_payload' => ['hero_headline' => 'What we know about keeping basements dry', 'intro' => 'Browse every article below.'],
    ]);
}

it('related articles: the silo siblings sharing the most title words, newest first among equals, never itself or another silo', function () {
    $site = Site::factory()->create();
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $drains = Silo::factory()->create(['site_id' => $site->id, 'name' => 'French Drains']);
    $me = blPost($site, $pumps, 'Sump Pump Maintenance Tips to Keep Wildlife Out', 'me', '2026-09-20');
    $close = blPost($site, $pumps, 'Sump Pump Maintenance Checklist for Homeowners', 'close', '2026-08-01');
    $closer = blPost($site, $pumps, 'Sump Pump Maintenance to Stop Wildlife Damage', 'closer', '2026-07-01');
    $far = blPost($site, $pumps, 'Where Should Discharge Water Go', 'far', '2026-09-22');
    $farOld = blPost($site, $pumps, 'Backup Battery Basics', 'far-old', '2026-01-01');
    blPost($site, $drains, 'Sump Pump Maintenance in Wet Yards', 'other-silo', '2026-09-21');
    blPost($site, $pumps, 'Sump Pump Maintenance Draft', 'draft', '2026-09-23', status: 'needs_review');

    $related = app(BlogFeeds::class)->related($me);

    expect($related->pluck('slug')->all())->toBe(['closer', 'close', 'far']); // 4 shared, 3 shared, then newest of the rest
});

it('the blog index lists every published post, grouped by silo with the un-routed last, newest first inside', function () {
    $site = Site::factory()->create();
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $drains = Silo::factory()->create(['site_id' => $site->id, 'name' => 'French Drains']);
    blPost($site, $pumps, 'Older Pump Post', 'older-pump', '2026-08-01');
    blPost($site, $pumps, 'Newer Pump Post', 'newer-pump', '2026-09-01');
    blPost($site, $drains, 'Drain Post', 'drain', '2026-09-05');
    blPost($site, null, 'Unrouted Post', 'unrouted', '2026-09-10');
    blPost($site, $pumps, 'Not Yet', 'not-yet', '2026-09-11', status: 'needs_review');

    $index = app(BlogFeeds::class)->index($site->id);

    expect(array_column($index, 'silo'))->toBe(['French Drains', 'Sump Pumps', 'More articles'])
        ->and($index[1]['posts']->pluck('slug')->all())->toBe(['newer-pump', 'older-pump'])
        ->and($index[2]['posts']->pluck('slug')->all())->toBe(['unrouted']);
});

it('composes the Blog page: hero from the drafted slots, every post as a linked row under its silo, and the fine identity in the blob', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    blPost($site, $pumps, 'Newer Pump Post', 'newer-pump', '2026-09-01');
    blPost($site, $pumps, 'Older Pump Post', 'older-pump', '2026-08-01');
    $page = blBlogPage($site);

    $markup = app(BlockContentAssembler::class)->compose($page->fresh(), $page->slot_payload, []);

    expect($markup)->toBeString()
        ->toContain('What we know about keeping basements dry')
        ->toContain('Sump Pumps')
        ->toContain('href="/newer-pump/"')
        ->toContain('href="/older-pump/"')
        ->toContain('Sep 1, 2026');
    expect(strpos($markup, 'newer-pump'))->toBeLessThan(strpos($markup, 'older-pump'));

    $blob = app(MetaBlobAssembler::class)->assemble($page->fresh(), collect());
    expect($blob['page_type'])->toBe('blog');

    // Nothing published yet → an honest line, not an empty section.
    $empty = Site::factory()->create(['domain_url' => 'https://fresh.example']);
    $emptyPage = blBlogPage($empty);
    expect(app(BlockContentAssembler::class)->compose($emptyPage->fresh(), $emptyPage->slot_payload, []))
        ->toContain('Articles are on the way');
});

it('a published post carries its related articles under the body at push time, never stored, and none when alone', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $post = blPost($site, $pumps, 'Sump Pump Maintenance Tips', 'tips', '2026-09-20');
    $sibling = blPost($site, $pumps, 'Sump Pump Maintenance Checklist', 'checklist', '2026-08-01');

    $blob = app(MetaBlobAssembler::class)->assemble($post->fresh(), collect());
    expect($blob['post_content'])->toContain('<p>Article body about Sump Pump Maintenance Tips.</p>')
        ->toContain('Related articles')
        ->toContain('href="/checklist/"')
        ->and($post->fresh()->body)->not->toContain('Related articles'); // assembled, not written back

    $blob = app(MetaBlobAssembler::class)->assemble($sibling->fresh(), collect());
    expect($blob['post_content'])->toContain('href="/tips/"');

    $lonely = blPost(Site::factory()->create(['domain_url' => 'https://lonely.example']), null, 'Alone', 'alone', '2026-09-20');
    expect(app(MetaBlobAssembler::class)->assemble($lonely->fresh(), collect())['post_content'])->not->toContain('Related articles');
});

it('the internal-link graph counts the feed links the live site carries: silo feed, local feed, related articles, and the blog index', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $service = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published, 'silo_id' => $pumps->id, 'slug' => 'sump-pump-repair', 'title' => 'Sump Pump Repair', 'slot_payload' => ['x' => 'y']]);
    $town = Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'location_id' => null, 'parent_location_id' => 'loc-1', 'slug' => 'newark-nj', 'title' => 'Newark, NJ', 'slot_payload' => ['x' => 'y']]);
    $blog = blBlogPage($site);
    $a = blPost($site, $pumps, 'Sump Pump Maintenance Tips', 'a', '2026-09-20');
    $b = blPost($site, $pumps, 'Sump Pump Maintenance Checklist', 'b', '2026-08-01');
    ContentTown::create(['site_id' => $site->id, 'content_id' => $b->id, 'town' => 'newark', 'town_display' => 'Newark']);

    $graph = app(InternalLinkGraph::class)->build($site);

    expect($graph->inbound($a->id))->toContain($service->id)   // silo feed on the service page
        ->toContain($blog->id)                                  // the index lists every post
        ->toContain($b->id)                                     // b's related articles name a
        ->and($graph->inbound($b->id))->toContain($town->id)     // local feed on the town page
        ->toContain($a->id)
        ->and($graph->inbound($service->id))->not->toContain($a->id); // feeds link pages → posts, never back
});

it('the Blog page joins the header main nav and the company links once it is published', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sewergurus.com']);
    blBlogPage($site);

    $profile = app(SiteProfileAssembler::class)->assemble($site->fresh());

    expect(array_column($profile['nav'], 'label'))->toContain('Blog')
        ->and(collect($profile['nav'])->firstWhere('label', 'Blog')['url'])->toBe('https://sewergurus.com/blog/')
        ->and(array_column($profile['company'], 'label'))->toContain('Blog');
});
