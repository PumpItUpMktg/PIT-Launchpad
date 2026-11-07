<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\RefreshPostForQuestion;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_operate_enabled', true);
});

it('shows the Refresh suggested panel and the asked chip on the Operate blog board, and Refresh queues the job', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $mark = ['query' => 'sump pump installation cost', 'phrasings' => [], 'impressions' => 373, 'clicks' => 0, 'position' => 58.6, 'reason' => 'r', 'mined_at' => '2026-11-06T23:07:00+00:00',
        'also' => [['query' => 'how much does it cost to install a sump pump', 'impressions' => 78], ['query' => 'cost to install sump pump', 'impressions' => 58]]];
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'cost', 'title' => 'Sump Pump Renovation Cost', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => $mark]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'busy', 'title' => 'Busy post', 'body' => '<p>x</p>', 'meta' => ['generating_at' => now()->toIso8601String(), 'question_refresh' => $mark]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'slug' => 'reviewing', 'title' => 'Reviewing post', 'body' => '<p>x</p>', 'meta' => ['question_refresh' => [...$mark, 'refreshed_at' => now()->toIso8601String()]]]);
    Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Candidate, 'source_name' => 'questions', 'draft_trigger' => DraftTrigger::Question, 'title' => 'Ejector pump replacement cost', 'body' => null,
        'meta' => ['question' => ['query' => 'ejector pump replacement cost', 'phrasings' => ['ejector pump cost'], 'impressions' => 85, 'clicks' => 0, 'position' => 26.2]],
    ]);

    $page = Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)
        ->assertOk()
        ->assertSee('Refresh suggested')
        ->assertSee('sump pump installation cost')
        ->assertSee('asked 373×')
        ->assertSee('+2 more questions')
        ->assertSee('refreshing…')
        ->assertSee('needs review — see the Review tab')
        ->assertSee('asked 85×')
        ->assertSeeHtml("refreshForQuestion('".$post->id."')");

    $page->call('refreshForQuestion', (string) $post->id);

    Queue::assertPushed(RefreshPostForQuestion::class, fn (RefreshPostForQuestion $j) => $j->contentId === (string) $post->id);
    expect($post->fresh()->meta['generating_at'])->not->toBeNull();
});

it('shows the refresh diff on the Operate review card', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::NeedsReview, 'slug' => 'r', 'title' => 'Sump Pump Installation Cost', 'body' => '<p>body</p>',
        'meta' => ['question_refresh' => [
            'query' => 'sump pump installation cost', 'refreshed_at' => '2026-11-07T10:00:00+00:00', 'answer' => 'A few hundred to a few thousand dollars.',
            'faq' => [['question' => 'q1?', 'answer' => 'a1']],
            'proposal' => ['before' => ['title' => 'Sump Pump Renovation Cost', 'seo_title' => 's', 'meta_description' => 'm'], 'after' => ['title' => 'Sump Pump Installation Cost', 'seo_title' => 's', 'meta_description' => 'm'], 'changed' => ['title']],
        ]],
    ]);

    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->set('tab', 'review')
        ->assertOk()
        ->assertSee('Refreshed for “sump pump installation cost”')
        ->assertSee('1 Q&A pair')
        ->assertSee('A few hundred to a few thousand dollars.')
        ->assertSee('Sump Pump Renovation Cost');
});
