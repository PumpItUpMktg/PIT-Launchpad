<?php

use App\ContentEngine\Coverage\NewsAngleRefresher;
use App\Enums\ContentStatus;
use App\Enums\RefreshTrigger;
use App\Integrations\Claude\ClaudeClient;
use App\Jobs\RefreshPostForNewsAngle;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeClaudeClient;

const NA_GOOD = '{"update": "The Inquirer reports Philadelphia\'s century-old stormwater system is sending water into basements after every heavy rain. If your home sits in one of those low blocks, check that your sump pump\'s check valve closes and that the discharge line clears the foundation before the next storm.", "title": "Sump Pump Maintenance Checklist for Philadelphia Homeowners", "seo_title": "Sump Pump Maintenance Checklist — Philly Storm Edition", "meta_description": "A dated maintenance checklist for Philadelphia basements after the latest stormwater flooding."}';

function naPost(Site $site, array $overrides = []): Content
{
    return Content::factory()->post()->create(array_merge([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-maintenance-checklist',
        'title' => 'Sump Pump Maintenance Checklist for Homeowners',
        'body' => '<div class="lp-question-answer"><p><strong>How often should you check a sump pump?</strong> Monthly.</p></div><p>Pull the lid and look at the float.</p><p>Pour a bucket of water in and watch it cycle.</p>',
        'wp_post_id' => 77, 'version' => 2, 'refresh_count' => 0,
        'meta' => ['seo' => ['title' => 'Sump Pump Maintenance Checklist', 'meta_description' => 'Keep the pump ready.'], 'generating_at' => now()->toIso8601String()],
    ], $overrides));
}

function naCandidate(Site $site, Content $post): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Philly has 100-year-old stormwater infrastructure and 72,000 easily clogged inlets',
        'body' => null, 'source_name' => 'Inquirer.com', 'source_url' => 'https://www.inquirer.com/news/stormwater-x', 'angle_hint' => 'What an aging stormwater system means for your basement',
        'meta' => ['shelf_life' => 'topical', 'story_place' => 'Philadelphia, PA', 'source_published_at' => '2026-11-12', 'coverage' => ['verdict' => 'refresh', 'twin_id' => $post->id, 'twin_title' => $post->title, 'folding_at' => now()->toIso8601String()]],
    ]);
}

it('folds the hook into the live post: a dated update block after the answer block, the outlet cited, a headline proposal with the diff, the refresh shape, and the candidate closed as folded', function () {
    app()->instance(ClaudeClient::class, $fake = new FakeClaudeClient(NA_GOOD));
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $post = naPost($site);
    $candidate = naCandidate($site, $post);

    $result = app(NewsAngleRefresher::class)->refresh($post, $candidate);
    $post->refresh();
    $candidate->refresh();

    expect($post->status)->toBe(ContentStatus::NeedsReview)
        ->and($post->version)->toBe(3)->and($post->refresh_count)->toBe(1)->and($post->last_refreshed_at)->not->toBeNull()
        ->and($post->title)->toBe('Sump Pump Maintenance Checklist for Philadelphia Homeowners')
        ->and($post->meta['seo']['title'])->toBe('Sump Pump Maintenance Checklist — Philly Storm Edition')
        ->and($post->meta)->not->toHaveKey('generating_at')
        ->and($post->body)->toStartWith('<div class="lp-question-answer">')
        ->and($post->body)->toContain('<div class="lp-news-update"><p><strong>Update ('.now()->format('M Y').'):</strong> The Inquirer reports')
        ->and($post->body)->toContain('<span class="lp-news-source">Source: <a href="https://www.inquirer.com/news/stormwater-x" rel="nofollow noopener" target="_blank">Inquirer.com</a></span>')
        ->and($post->body)->toContain('<p>Pull the lid and look at the float.</p>')
        ->and(substr_count($post->body, 'lp-news-update'))->toBe(1)
        ->and(strpos($post->body, 'lp-news-update'))->toBeGreaterThan(strpos($post->body, 'lp-question-answer'));

    $mark = $post->meta['news_refresh'];
    expect($mark['candidate_id'])->toBe($candidate->id)->and($mark['source_name'])->toBe('Inquirer.com')->and($mark['story_place'])->toBe('Philadelphia, PA')
        ->and($mark['proposal']['changed'])->toBe(['title', 'seo_title', 'meta_description'])
        ->and($mark['proposal']['before']['title'])->toBe('Sump Pump Maintenance Checklist for Homeowners')
        ->and($result->update)->toStartWith('The Inquirer reports');

    $event = RefreshEvent::withoutGlobalScopes()->where('content_id', $post->id)->sole();
    expect($event->trigger)->toBe(RefreshTrigger::NewsDevelopment)->and($event->note)->toContain('Philly has 100-year-old')->toContain('(Inquirer.com)');

    expect($candidate->status)->toBe(ContentStatus::Rejected)
        ->and($candidate->reject_reason)->toBe('Folded into “Sump Pump Maintenance Checklist for Philadelphia Homeowners”')
        ->and($candidate->meta['coverage']['folded_into'])->toBe($post->id)
        ->and($candidate->meta['coverage'])->not->toHaveKey('folding_at');

    expect($fake->prompts[0])->toContain('headline: Philly has 100-year-old')->toContain('outlet: Inquirer.com')->toContain('place: Philadelphia, PA')
        ->toContain('BRAND: SPG')->toContain('body (text): Pull the lid')->not->toContain('How often should you check');   // the lane block is not in the excerpt
});

it('a re-run replaces the earlier update block; a post with no answer block gets the update first; the title stays when the model returns it unchanged', function () {
    app()->instance(ClaudeClient::class, new FakeClaudeClient('{"update": "Second update.", "title": "", "seo_title": "", "meta_description": ""}'));
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = naPost($site, ['body' => '<div class="lp-news-update"><p><strong>Update (Oct 2026):</strong> First update.</p></div><p>Core paragraph.</p>']);
    $candidate = naCandidate($site, $post);

    app(NewsAngleRefresher::class)->refresh($post, $candidate);
    $post->refresh();

    expect($post->body)->toStartWith('<div class="lp-news-update"><p><strong>Update ('.now()->format('M Y').'):</strong> Second update.')
        ->and($post->body)->not->toContain('First update.')
        ->and($post->body)->toContain('<p>Core paragraph.</p>')
        ->and($post->title)->toBe('Sump Pump Maintenance Checklist for Homeowners')
        ->and($post->meta['news_refresh']['proposal']['changed'])->toBe([]);
});

it('writes nothing when the response carries no update, and the job records the failure on both rows', function () {
    app()->instance(ClaudeClient::class, new FakeClaudeClient('{"update": "", "title": "x"}'));
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $post = naPost($site, ['meta' => ['seo' => ['title' => 'S', 'meta_description' => 'M']]]);
    $candidate = naCandidate($site, $post);

    expect(fn () => app(NewsAngleRefresher::class)->refresh($post, $candidate))->toThrow(RuntimeException::class, 'no update text');
    expect($post->fresh()->status)->toBe(ContentStatus::Published)->and($post->fresh()->version)->toBe(2)
        ->and($candidate->fresh()->status)->toBe(ContentStatus::Candidate)
        ->and(RefreshEvent::withoutGlobalScopes()->count())->toBe(0);

    // Through the job: enqueue stamps both rows, a failed refresh clears them with the cause.
    Queue::fake();
    RefreshPostForNewsAngle::enqueue($post, $candidate);
    Queue::assertPushed(RefreshPostForNewsAngle::class);
    expect($post->fresh()->meta['generating_at'])->not->toBeNull()->and($candidate->fresh()->meta['coverage']['folding_at'])->not->toBeNull();
    (new RefreshPostForNewsAngle($post->id, $candidate->id))->handle(app(NewsAngleRefresher::class));
    expect($post->fresh()->meta)->not->toHaveKey('generating_at')->and($post->fresh()->draftError())->toContain('no update text')
        ->and($candidate->fresh()->meta['coverage'])->not->toHaveKey('folding_at')
        ->and($candidate->fresh()->meta['coverage']['fold_error'])->toContain('no update text')
        ->and($candidate->fresh()->status)->toBe(ContentStatus::Candidate);
});

it('refuses a post that is not live, and a candidate that is the post itself', function () {
    app()->instance(ClaudeClient::class, new FakeClaudeClient(NA_GOOD));
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $draft = naPost($site, ['status' => ContentStatus::NeedsReview]);
    $candidate = naCandidate($site, $draft);
    expect(fn () => app(NewsAngleRefresher::class)->refresh($draft, $candidate))->toThrow(InvalidArgumentException::class, 'not a live post');

    $live = naPost($site, ['slug' => 'live']);
    expect(fn () => app(NewsAngleRefresher::class)->refresh($live, $live))->toThrow(InvalidArgumentException::class, 'not a blog candidate');
});
