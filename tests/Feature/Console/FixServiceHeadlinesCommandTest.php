<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\KeywordSource;
use App\Enums\PageType;
use App\Integrations\Claude\ClaudeClient;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use App\Publishing\Seo\HeadlineKeywordFixer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeClaudeClient;

function cmdOffTargetPage(Site $site): Content
{
    $kw = Keyword::create(['site_id' => $site->id, 'query' => 'sump pump installation', 'source' => KeywordSource::Seed, 'status' => 'candidate']);

    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'status' => ContentStatus::Published,
        'page_type' => PageType::Service,
        'target_keyword_id' => $kw->id,
        'wp_post_id' => 99,
        'slug' => 'sump-pump-installation',
        'title' => 'Keep Your Basement Dry',
        'slot_payload' => ['hero_headline' => 'Keep Your Basement Dry'],
        'meta' => ['seo' => ['title' => 'Keep Your Basement Dry', 'meta_description' => 'A dry basement.']],
    ]);
}

beforeEach(function () {
    $fake = new FakeClaudeClient(json_encode([
        'hero_headline' => 'Sump Pump Installation, Done in a Day',
        'seo_title' => 'Sump Pump Installation for a Dry Basement',
        'meta_description' => 'Professional sump pump installation, clean and same-day.',
    ]));
    // The fixer's ClaudeClient is contextually bound to the Haiku client — override it with the fake.
    app()->when(HeadlineKeywordFixer::class)
        ->needs(ClaudeClient::class)
        ->give(fn () => $fake);
});

it('dry-runs by default — reports the rewrite but writes nothing', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $page = cmdOffTargetPage($site);

    $code = Artisan::call('launchpad:fix-service-headlines', ['--site' => 'SPG']);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('DRY RUN')
        ->and($out)->toContain('/sump-pump-installation')
        ->and($out)->toContain('Sump Pump Installation, Done in a Day');

    expect($page->fresh()->slot_payload['hero_headline'])->toBe('Keep Your Basement Dry'); // unchanged
    Queue::assertNotPushed(PublishContent::class);
});

it('--apply writes the rewrite and queues a re-publish', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $page = cmdOffTargetPage($site);

    Artisan::call('launchpad:fix-service-headlines', ['--site' => 'SPG', '--apply' => true]);

    expect($page->fresh()->slot_payload['hero_headline'])->toBe('Sump Pump Installation, Done in a Day');
    Queue::assertPushed(PublishContent::class);
});

it('reports a clean bill when no headline is off-target', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    expect(Artisan::call('launchpad:fix-service-headlines', ['--site' => 'SPG']))->toBe(0);
    expect(Artisan::output())->toContain('No off-target');
});

it('errors for an unknown site', function () {
    expect(Artisan::call('launchpad:fix-service-headlines', ['--site' => 'Nope Inc']))->toBe(1);
});
