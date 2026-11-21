<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\DraftTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateBlog;
use App\Jobs\RefreshPostForQuestion;
use App\Jobs\ReinspectRefreshedPost;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryDaily;
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

it('the "Questions lane" filter choice narrows Candidates to the lane\'s candidates and Review to its refreshes', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'source_name' => 'questions', 'draft_trigger' => DraftTrigger::Question, 'title' => 'Ejector pump replacement cost', 'body' => null, 'meta' => ['question' => ['query' => 'q', 'phrasings' => [], 'impressions' => 85, 'clicks' => 0, 'position' => 26.2]]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'source_name' => 'youtube', 'title' => 'Basement waterproofing done right', 'body' => null]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'slug' => 'r', 'title' => 'Refreshed cost post', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => ['query' => 'sump pump installation cost', 'refreshed_at' => now()->toIso8601String(), 'answer' => 'A.', 'faq' => [], 'proposal' => ['before' => [], 'after' => [], 'changed' => []]]]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'slug' => 'n', 'title' => 'Ordinary news draft', 'body' => '<p>x</p>']);

    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->set('siloFilter', OperateBlog::QUESTIONS_FILTER)
        ->assertOk()
        ->assertSee('Ejector pump replacement cost')->assertDontSee('Basement waterproofing done right')
        ->set('tab', 'review')
        ->assertSee('Refreshed cost post')->assertDontSee('Ordinary news draft');
});

it('folds refreshed posts under a count with the refresh date and the refresh-again date, offers Refresh again once the cooldown is over, and refuses a second pass inside it', function () {
    Queue::fake();
    config(['launchpad.questions.refresh_cooldown_days' => 30]);
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $base = ['query' => 'sump pump installation cost', 'phrasings' => [], 'impressions' => 340, 'clicks' => 0, 'position' => 58.4, 'reason' => 'r', 'mined_at' => '2026-11-06T23:07:00+00:00', 'also' => []];
    $recent = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'recent', 'title' => 'Recently refreshed', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => [...$base, 'refreshed_at' => now()->subDays(14)->toIso8601String()]]]);
    $overdue = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'overdue', 'title' => 'Refreshed long ago', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => [...$base, 'query' => 'how long do sump pumps last', 'impressions' => 142, 'refreshed_at' => now()->subDays(45)->toIso8601String()]]]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'open', 'title' => 'Never refreshed', 'body' => '<p>x</p>',
        'meta' => ['question_refresh' => [...$base, 'query' => 'radon testing cost', 'impressions' => 81]]]);

    // Search Console either side of the recent refresh — shown on the done row as progress.
    $url = 'https://spg.example/recent/';
    GscUrlQueryDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', 'b'), 'date' => now()->subDays(20)->toDateString(), 'url' => $url, 'query' => 'sump pump installation cost', 'country' => 'usa', 'device' => 'DESKTOP', 'impressions' => 100, 'clicks' => 0, 'ctr' => 0, 'position' => 58.4]);
    GscUrlQueryDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', 'a'), 'date' => now()->subDays(5)->toDateString(), 'url' => $url, 'query' => 'sump pump installation cost', 'country' => 'usa', 'device' => 'DESKTOP', 'impressions' => 190, 'clicks' => 3, 'ctr' => 0, 'position' => 41.2]);
    GscUrlDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', 'pb'), 'date' => now()->subDays(20)->toDateString(), 'url' => $url, 'impressions' => 400, 'clicks' => 4, 'ctr' => 0, 'position' => 30.0]);
    GscUrlDaily::create(['site_id' => $site->id, 'grain_hash' => hash('sha256', 'pa'), 'date' => now()->subDays(5)->toDateString(), 'url' => $url, 'impressions' => 600, 'clicks' => 8, 'ctr' => 0, 'position' => 25.0]);

    $page = Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)
        ->assertSee('2 open · 1 done')
        ->assertSee('6 better · 0 worse')
        ->assertSeeHtml('58.4 → <b>41.2</b>')->assertSee('▲ 17.2 better')
        ->assertSeeHtml('100 → <b>190</b>')->assertSee('▲ +90 (+90%)')
        ->assertSeeHtml('0 → <b>3</b>')->assertSee('▲ +3')
        ->assertSeeHtml('400 → <b>600</b>')->assertSee('▲ +200 (+50%)')
        ->assertSee('Asked')
        ->assertSee('14d before → 14d since')
        ->assertSee('Google: Not checked yet')->assertSee('Check Google now')
        ->assertSee('Show')->assertSee('1 refreshed')
        ->assertSee('✓ Refreshed '.now()->subDays(14)->format('M j'))
        ->assertSee('Refresh again from '.now()->addDays(16)->format('M j').' · in 16 days')
        ->assertSee('Refreshed '.now()->subDays(45)->format('M j').' · ready again')
        ->assertSee('Refresh again')
        ->assertSeeHtml("refreshForQuestion('".$overdue->id."')")
        ->assertDontSeeHtml("refreshForQuestion('".$recent->id."')");

    // Inside the cooldown the action refuses and nothing is queued.
    $page->call('refreshForQuestion', (string) $recent->id);
    Queue::assertNotPushed(RefreshPostForQuestion::class);
    expect($recent->fresh()->meta)->not->toHaveKey('generating_at');

    // "Check Google now" queues one re-inspection for the row and the chip reads checking….
    $page->call('checkGoogle', (string) $recent->id);
    Queue::assertPushed(ReinspectRefreshedPost::class, fn ($j) => $j->contentId === (string) $recent->id);
    Livewire::test(OperateBlog::class)->set('siteFilter', $site->id)->assertSee('Google: checking…');

    // Past the cooldown a second pass queues.
    $page->call('refreshForQuestion', (string) $overdue->id);
    Queue::assertPushed(RefreshPostForQuestion::class, fn (RefreshPostForQuestion $j) => $j->contentId === (string) $overdue->id);
    expect($overdue->fresh()->meta['generating_at'])->not->toBeNull();
});
