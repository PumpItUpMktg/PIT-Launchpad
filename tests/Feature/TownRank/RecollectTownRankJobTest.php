<?php

use App\Enums\UserRole;
use App\Filament\Pages\TownRankPage;
use App\Jobs\RecollectTownRank;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Models\User;
use App\TownRank\TownRankRecollectProgress;
use App\TownRank\TownRankScanner;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function recollectJobSite(): array
{
    config(['services.dataforseo.rate_limit_backoff_ms' => 0]);
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 3, 'found_count' => 0, 'scanned_at' => now()->subDays(2)]);
    $point = fn (string $label, string $task, array $top) => TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => $label, 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => $task, 'collected_at' => now()->subDays(2), 'rank' => null, 'top_results' => $top]);
    $point('masked-town', 'task-m', [['position' => 5, 'url' => 'https://google.com/goto?url=CAESkgEB', 'domain' => 'google.com']]);
    $point('phantom-town', 'task-p', []);
    $point('real-miss', 'task-r', [['position' => 1, 'url' => 'https://rival.com', 'domain' => 'rival.com']]);

    return [$site, $kw, $scan];
}

function taskBody(array $items): array
{
    return ['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]]];
}

it('start() counts the phantom + masked backlog, records a running chain, dispatches once, and refuses a second chain while live', function () {
    Queue::fake();
    [$site] = recollectJobSite();

    expect(RecollectTownRank::backlog($site->id))->toBe(2)   // the real miss is not work
        ->and(RecollectTownRank::start($site->id))->toBe(2)
        ->and(TownRankRecollectProgress::isRunning($site->id))->toBeTrue()
        ->and(RecollectTownRank::start($site->id))->toBeNull();   // already running
    Queue::assertPushed(RecollectTownRank::class, 1);
});

it('the job re-reads the backlog (a masked town recovers its rank by the title suffix, a phantom by a clean read), updates the progress, and finishes', function () {
    Queue::fake();
    [$site, , $scan] = recollectJobSite();
    Http::fake([
        '*/task_get/advanced/task-m' => Http::response(taskBody([
            ['type' => 'organic', 'rank_absolute' => 2, 'url' => 'https://google.com/goto?url=CAESaa', 'domain' => 'google.com', 'title' => 'Sump Pump Service in Mansfield, NJ | Sump Pump Gurus', 'website_name' => 'google.com', 'breadcrumb' => 'https://google.com › goto'],
        ])),
        '*/task_get/advanced/task-p' => Http::response(taskBody([
            ['type' => 'organic', 'rank_absolute' => 1, 'url' => 'https://rival.com/x', 'domain' => 'rival.com'],
        ])),
    ]);
    RecollectTownRank::start($site->id);

    (new RecollectTownRank($site->id))->handle(app(TownRankScanner::class));

    $byLabel = $scan->points()->get()->keyBy('label');
    $p = TownRankRecollectProgress::get($site->id);
    expect($byLabel['masked-town']->rank)->toBe(2)
        ->and($byLabel['phantom-town']->rank)->toBeNull()
        ->and($byLabel['phantom-town']->top_results)->toHaveCount(1)   // a real not-found now, no longer a phantom
        ->and($scan->fresh()->found_count)->toBe(1)
        ->and($p['status'])->toBe(TownRankRecollectProgress::STATUS_DONE)
        ->and($p)->toMatchArray(['read' => 2, 'ranked' => 1, 'not_found' => 1, 'skipped' => 0, 'remaining' => 0])
        ->and(RecollectTownRank::backlog($site->id))->toBe(0);
    Queue::assertPushed(RecollectTownRank::class, 1);   // the start — no continuation needed
});

it('a chunk that resolved something with work left dispatches a continuation; one that only hit unreadable tasks ends the chain', function () {
    Queue::fake();
    [$site, , $scan] = recollectJobSite();
    Http::fake([
        '*/task_get/advanced/task-m' => Http::response(taskBody([['type' => 'organic', 'rank_absolute' => 3, 'url' => 'https://spg.com/a', 'domain' => 'spg.com']])),
        '*/task_get/advanced/task-p' => Http::response(['status_code' => 50000, 'status_message' => 'Internal Error'], 500),
    ]);
    RecollectTownRank::start($site->id);
    Queue::assertPushed(RecollectTownRank::class, 1);

    (new RecollectTownRank($site->id))->handle(app(TownRankScanner::class));
    // Masked recovered (#3); the phantom's task failed → stays in the backlog; progress made → continue.
    expect(TownRankRecollectProgress::get($site->id))->toMatchArray(['status' => 'running', 'ranked' => 1, 'skipped' => 1, 'remaining' => 1]);
    Queue::assertPushed(RecollectTownRank::class, 2);

    (new RecollectTownRank($site->id, null, true))->handle(app(TownRankScanner::class));
    // Nothing new resolved this time: the chain ends rather than looping on the dead task.
    expect(TownRankRecollectProgress::get($site->id)['status'])->toBe(TownRankRecollectProgress::STATUS_DONE);
    Queue::assertPushed(RecollectTownRank::class, 2);
});

it('the Town rank board offers the re-read, queues it, and shows the live progress line; --queue does the same from the command', function () {
    Queue::fake();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    [$site] = recollectJobSite();

    Livewire::test(TownRankPage::class)->set('siteId', $site->id)
        ->assertSee('Re-read masked towns (2)')
        ->call('recollectMasked')
        ->assertSee('Re-reading masked towns:')
        ->assertSee('2 of 2 left');
    Queue::assertPushed(RecollectTownRank::class, 1);

    TownRankRecollectProgress::clear($site->id);
    expect(Artisan::call('launchpad:town-rank-recollect', ['site' => 'Sump Pump Gurus', '--queue' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Queued: 2 town(s)');
    Queue::assertPushed(RecollectTownRank::class, 2);
    expect(Artisan::call('launchpad:town-rank-recollect', ['site' => 'Sump Pump Gurus', '--queue' => true]))->toBe(0);
    expect(Artisan::output())->toContain('already running');
});
