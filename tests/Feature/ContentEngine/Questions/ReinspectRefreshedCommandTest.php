<?php

use App\Jobs\ReinspectRefreshedPost;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

it('queues a re-inspection at the 3- and 10-day checkpoints after the republish, skips posts already inspected past the checkpoint, and is scheduled daily', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $mark = fn (int $daysAgo) => ['question_refresh' => ['query' => 'q', 'refreshed_at' => now()->subDays($daysAgo + 1)->toIso8601String(), 'republished_at' => now()->subDays($daysAgo)->toIso8601String()]];
    $dueDay3 = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'd3', 'title' => 'Due day 3', 'meta' => $mark(4)]);
    $doneDay3 = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'done3', 'title' => 'Done day 3', 'meta' => $mark(5)]);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $doneDay3->id, 'origin' => 'content', 'url' => 'https://spg.example/done3/', 'url_normalized' => 'spg.example/done3', 'last_inspected_at' => now()->subDays(1)]);
    $dueDay10 = Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'd10', 'title' => 'Due day 10', 'meta' => $mark(12)]);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $dueDay10->id, 'origin' => 'content', 'url' => 'https://spg.example/d10/', 'url_normalized' => 'spg.example/d10', 'last_inspected_at' => now()->subDays(8)]);   // checked at day 4, not since day 10
    Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'fresh', 'title' => 'Too fresh', 'meta' => $mark(1)]);
    Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'old', 'title' => 'Long ago', 'meta' => $mark(40)]);
    Content::factory()->post()->published()->create(['site_id' => $site->id, 'slug' => 'plain', 'title' => 'Never refreshed', 'meta' => []]);

    Artisan::call('launchpad:reinspect-refreshed', ['--site' => 'SPG']);
    $out = Artisan::output();
    expect($out)->toContain('Read-only')->toContain('day-3 check due')->toContain('day-10 check due')->toContain('Done day 3')->toContain('nothing due')
        ->toContain('2 inspection(s) due — nothing queued')->not->toContain('Too fresh');
    Queue::assertNothingPushed();

    Artisan::call('launchpad:reinspect-refreshed', ['--site' => $site->id, '--execute' => true]);
    expect(Artisan::output())->toContain('2 inspection(s) queued');
    Queue::assertPushed(ReinspectRefreshedPost::class, 2);
    Queue::assertPushed(ReinspectRefreshedPost::class, fn (ReinspectRefreshedPost $j) => $j->contentId === (string) $dueDay3->id);
    Queue::assertPushed(ReinspectRefreshedPost::class, fn (ReinspectRefreshedPost $j) => $j->contentId === (string) $dueDay10->id);
    expect($dueDay3->fresh()->meta['refresh_crawl']['requested_at'])->not->toBeNull();

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'launchpad:reinspect-refreshed'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('20 5 * * *');
});
