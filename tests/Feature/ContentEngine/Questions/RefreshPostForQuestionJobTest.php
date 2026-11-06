<?php

use App\ContentEngine\Questions\QuestionRefresher;
use App\Enums\ContentStatus;
use App\Integrations\Claude\ClaudeClient;
use App\Jobs\RefreshPostForQuestion;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeClaudeClient;

function jobMarkedPost(Site $site): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'how-long', 'title' => 'How long do sump pumps last',
        'body' => '<p>Seven to ten years is typical.</p>', 'wp_post_id' => 7,
        'meta' => ['question_refresh' => ['query' => 'how long do sump pumps last', 'phrasings' => [], 'impressions' => 132, 'clicks' => 0, 'position' => 46.0]],
    ]);
}

it('enqueue stamps the generating marker and dispatches; the worker refreshes the post into review', function () {
    $site = Site::factory()->create();
    $post = jobMarkedPost($site);

    Queue::fake();
    RefreshPostForQuestion::enqueue($post, 'user-1');
    Queue::assertPushed(RefreshPostForQuestion::class, fn (RefreshPostForQuestion $j) => $j->contentId === (string) $post->id && $j->actorId === 'user-1');
    expect($post->fresh()->meta['generating_at'])->not->toBeNull();

    app()->instance(ClaudeClient::class, new FakeClaudeClient('{"answer": "Seven to ten years, with annual checks.", "faq": [], "title": "", "seo_title": "", "meta_description": ""}'));
    (new RefreshPostForQuestion((string) $post->id, 'user-1'))->handle(app(QuestionRefresher::class));

    $post->refresh();
    expect($post->status)->toBe(ContentStatus::NeedsReview)
        ->and($post->meta)->not->toHaveKey('generating_at')
        ->and($post->body)->toStartWith('<div class="lp-question-answer">');
});

it('records a failed refresh on the still-published row and clears the marker — never stuck "Refreshing"', function () {
    $site = Site::factory()->create();
    $post = jobMarkedPost($site);
    $post->markGenerating();

    app()->instance(ClaudeClient::class, new FakeClaudeClient('garbage'));
    (new RefreshPostForQuestion((string) $post->id))->handle(app(QuestionRefresher::class));

    $post->refresh();
    expect($post->status)->toBe(ContentStatus::Published)
        ->and($post->draftError())->not->toBeNull()
        ->and($post->meta)->not->toHaveKey('generating_at');

    $post->markGenerating();
    (new RefreshPostForQuestion((string) $post->id))->failed(new RuntimeException('timed out'));
    expect($post->fresh()->draftError())->toContain('timed out');
});

it('the command refreshes synchronously and prints the proposal', function () {
    $site = Site::factory()->create();
    $post = jobMarkedPost($site);
    app()->instance(ClaudeClient::class, new FakeClaudeClient('{"answer": "Seven to ten years.", "faq": [{"question": "When should I replace it?", "answer": "At the first sign of weak cycling."}], "title": "How Long Do Sump Pumps Last?", "seo_title": "How Long Do Sump Pumps Last? Lifespan & Replacement", "meta_description": "Seven to ten years is typical; here is how to tell yours is near the end."}'));

    expect(Artisan::call('launchpad:refresh-question', ['content' => (string) $post->id]))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('Direct answer')->toContain('Seven to ten years.')->toContain('1 pair(s)')
        ->toContain('Title (H1): “How long do sump pumps last” → “How Long Do Sump Pumps Last?”')
        ->toContain('needs_review');
    expect(Artisan::call('launchpad:refresh-question', ['content' => 'nope']))->toBe(1);
});
