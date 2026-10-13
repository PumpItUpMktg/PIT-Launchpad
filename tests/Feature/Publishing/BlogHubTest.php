<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\Silo;
use App\Models\Site;
use App\Publishing\Blocks\BlockContentAssembler;

/**
 * The Blog hub was a hero over a bare list of titles — the thin page Google crawled and declined. It now
 * opens on the newest articles as cards, then a topic map: each silo led by its pillar guide, every post
 * with its one-line excerpt.
 */
it('renders the Blog hub with a latest strip, a pillar lead per topic, and an excerpt per article', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $pillar = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service, 'status' => ContentStatus::Published,
        'title' => 'Sump Pump Services', 'slug' => 'sump-pump-services', 'wp_post_id' => 9, 'silo_id' => $pumps->id,
    ]);
    $pumps->forceFill(['pillar_content_id' => $pillar->id])->save();
    Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $pumps->id, 'matched_silo_id' => $pumps->id, 'status' => 'published', 'wp_post_id' => 1,
        'title' => 'Sump Pump Check Valve: What It Does', 'slug' => 'check-valve', 'published_at' => '2026-09-20',
        'body' => '<p>A check valve keeps discharged water from falling back into the pit. Here is how to spot one that is failing and what replacing it involves.</p>',
        'meta' => ['seo' => ['meta_description' => 'How a sump pump check valve works, the signs it is failing, and when to replace it.']],
    ]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'silo_id' => $pumps->id, 'matched_silo_id' => $pumps->id, 'status' => 'published', 'wp_post_id' => 2,
        'title' => 'Winterize Your Sump Pump', 'slug' => 'winterize', 'published_at' => '2026-09-10',
        'body' => '<p>Cold snaps freeze discharge lines and a frozen line burns out a pump. Clear the line, check the pitch, and test the float before the first hard frost arrives this season.</p>',
    ]);
    $blog = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'status' => ContentStatus::Published,
        'standard_type' => StandardPageType::Blog->value, 'slug' => 'blog', 'title' => 'Blog', 'wp_post_id' => 3,
        'slot_payload' => ['hero_headline' => 'What we know about keeping basements dry', 'intro' => 'Browse every article below.'],
    ]);

    $markup = app(BlockContentAssembler::class)->compose($blog->fresh(), $blog->slot_payload, []);

    expect($markup)
        ->toContain('New on the blog')                                                       // the latest strip
        ->toContain('Start with our guide: <a href="/sump-pump-services/">Sump Pump Services</a>')   // the topic's pillar lead
        ->toContain('lp-post-excerpt">How a sump pump check valve works, the signs it is failing, and when to replace it.')   // the drafted description
        ->toContain('lp-post-excerpt">Cold snaps freeze discharge lines and a frozen line burns out a pump. Clear the line, check the pitch,')   // from the body, plain text…
        ->toMatch('/lp-post-excerpt">Cold snaps[^<]{80,165}…<\/span>/')                                 // …trimmed at a word, ~160 chars
        ->toContain('Browse by topic')
        ->toContain('1 topic · 2 articles · newest Sep 20, 2026')                                         // the hub's own sentence about itself
        ->not->toContain('All 2 Sump Pumps articles');                                                   // nothing beyond the cap → no archive link
});

it('lists only the newest posts per topic and sends the rest to the topic archive, so the hub is a map rather than the whole inventory', function () {
    config()->set('launchpad.blog_hub.per_topic', 2);
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $pumps = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Grinder & Sewage Pump Service']);
    foreach (['2026-09-01' => 'Oldest', '2026-09-10' => 'Middle', '2026-09-20' => 'Newest'] as $date => $title) {
        Content::factory()->post()->create([
            'site_id' => $site->id, 'silo_id' => $pumps->id, 'matched_silo_id' => $pumps->id, 'status' => 'published', 'wp_post_id' => crc32($title) % 1000 + 10,
            'title' => $title.' grinder post', 'slug' => strtolower($title).'-grinder', 'published_at' => $date, 'body' => '<p>Grinder pumps chew what a sump pump cannot, and here is what that means for a finished basement bathroom.</p>',
        ]);
    }
    // A post routed to no silo: listed under "More articles", which has no archive to link.
    Content::factory()->post()->create(['site_id' => $site->id, 'silo_id' => null, 'matched_silo_id' => null, 'status' => 'published', 'wp_post_id' => 77, 'title' => 'Unrouted post', 'slug' => 'unrouted', 'published_at' => '2026-08-01', 'body' => '<p>An article that belongs to no topic yet.</p>']);
    $blog = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Utility, 'status' => ContentStatus::Published,
        'standard_type' => StandardPageType::Blog->value, 'slug' => 'blog', 'title' => 'Blog', 'wp_post_id' => 3, 'slot_payload' => [],
    ]);

    $markup = (string) app(BlockContentAssembler::class)->compose($blog->fresh(), [], []);
    $index = substr($markup, (int) strpos($markup, 'Browse by topic')); // the topic map, after the latest strip

    expect($index)
        ->toContain('Newest grinder post')->toContain('Middle grinder post')
        ->not->toContain('Oldest grinder post')                                                           // beyond the cap of 2 (it still shows in the latest strip)
        ->toContain('<a href="/category/grinder-sewage-pump-service/">All 3 Grinder &amp; Sewage Pump Service articles →</a>');
    expect($markup)
        ->toContain('Unrouted post')
        ->not->toContain('All 1 More articles')                                                           // no archive for the routed-nowhere group
        ->toContain('1 topic · 4 articles · newest Sep 20, 2026')
        // The latest strip now carries each card's excerpt, not just a title and a date.
        ->toContain('lp-post-excerpt">Grinder pumps chew what a sump pump cannot');
});
