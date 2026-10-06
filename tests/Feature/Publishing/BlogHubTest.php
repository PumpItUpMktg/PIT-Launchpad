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
        ->toContain('Browse by topic');
});
