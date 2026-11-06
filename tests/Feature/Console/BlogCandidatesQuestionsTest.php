<?php

use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Filament\Console\Pages\BlogCandidates;
use App\Filament\Console\Pages\BlogReview;
use App\Jobs\RefreshPostForQuestion;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('console');
    $this->actingAs(User::factory()->admin()->create());
});

it('shows a questions candidate with its demand tag and the refresh-suggested panel for a marked post', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Candidate, 'source_name' => 'questions', 'draft_trigger' => DraftTrigger::Question,
        'title' => 'How long do sump pumps last?', 'body' => null,
        'meta' => ['question' => ['query' => 'how long do sump pumps last', 'phrasings' => ['how long does a sump pump last'], 'impressions' => 140, 'clicks' => 3, 'position' => 9.0]],
    ]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'sump-pump-lifespan', 'title' => 'Sump pump lifespan', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => ['query' => 'when should i replace my sump pump', 'phrasings' => [], 'impressions' => 88, 'clicks' => 2, 'position' => 14.0, 'reason' => 'our post ranks #14.0 for it — refresh it', 'mined_at' => '2026-11-04T04:30:00+00:00']],
    ]);

    Livewire::test(BlogCandidates::class)->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('How long do sump pumps last?')
        ->assertSee('Asked 140×')
        ->assertSee('how long does a sump pump last')
        ->assertSee('Refresh suggested')
        ->assertSee('when should i replace my sump pump')
        ->assertSee('Sump pump lifespan')
        ->assertSee('pos 14.0');
});

it('offers Refresh on a marked live post, queues the job on click, and shows the refreshing / refreshed states', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    $mark = ['query' => 'when should i replace my sump pump', 'phrasings' => [], 'impressions' => 88, 'clicks' => 2, 'position' => 14.0, 'reason' => 'r', 'mined_at' => '2026-11-04T04:30:00+00:00'];
    $idle = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'a', 'title' => 'Idle post', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => $mark]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'b', 'title' => 'Busy post', 'body' => '<p>x</p>', 'meta' => ['generating_at' => now()->toIso8601String(), 'question_refresh' => $mark]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'c', 'title' => 'Done post', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => [...$mark, 'refreshed_at' => '2026-11-06T10:00:00+00:00']]]);

    $page = Livewire::test(BlogCandidates::class)->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Refreshing…')
        ->assertSee('refreshed 2026-11-06')
        ->assertSeeHtml("refreshForQuestion('".$idle->id."')");

    $page->call('refreshForQuestion', (string) $idle->id);

    Queue::assertPushed(RefreshPostForQuestion::class, fn (RefreshPostForQuestion $j) => $j->contentId === (string) $idle->id);
    expect($idle->fresh()->meta['generating_at'])->not->toBeNull();
});

it('shows the refresh diff on the review card', function () {
    $site = Site::factory()->create(['domain_url' => 'https://acme.test']);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'slug' => 'r', 'title' => 'Sump Pump Installation Cost', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => [
            'query' => 'sump pump installation cost', 'refreshed_at' => '2026-11-06T10:00:00+00:00', 'answer' => 'A few hundred to a few thousand dollars.',
            'faq' => [['question' => 'q1?', 'answer' => 'a1'], ['question' => 'q2?', 'answer' => 'a2']],
            'proposal' => ['before' => ['title' => 'Sump Pump Renovation Cost', 'seo_title' => 's', 'meta_description' => 'm'], 'after' => ['title' => 'Sump Pump Installation Cost', 'seo_title' => 's', 'meta_description' => 'm'], 'changed' => ['title']],
        ]],
    ]);

    Livewire::test(BlogReview::class)->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Refreshed for “sump pump installation cost”')
        ->assertSee('2 Q&A pairs')
        ->assertSee('A few hundred to a few thousand dollars.')
        ->assertSee('Sump Pump Renovation Cost');
});
