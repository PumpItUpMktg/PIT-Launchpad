<?php

use App\ContentEngine\Coverage\CandidateCoverage;
use App\Enums\ContentStatus;
use App\Integrations\Embedding\EmbeddingProvider;
use App\Integrations\Embedding\MockEmbeddingProvider;
use App\Jobs\RecheckCandidateCoverage;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('start() stamps queued and dispatches once per site; handle() re-stamps every open candidate and records the counts', function () {
    app()->instance(EmbeddingProvider::class, new MockEmbeddingProvider);
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'title' => 'Sump Pump Maintenance Checklist for Homeowners', 'slug' => 'checklist', 'body' => '<p>b</p>']);
    $twinned = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'Sump Pump Maintenance Checklist for Homeowners This Fall', 'body' => null, 'meta' => ['coverage' => ['verdict' => 'author']]]);
    $fresh = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::InReview, 'title' => 'Radon fan noise at night', 'body' => null, 'meta' => []]);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Rejected, 'title' => 'Closed', 'body' => null, 'meta' => []]);

    Queue::fake();
    RecheckCandidateCoverage::start($site);
    RecheckCandidateCoverage::start($site);   // a second click while queued is a no-op
    Queue::assertPushed(RecheckCandidateCoverage::class, 1);
    expect(RecheckCandidateCoverage::status($site->id)['state'])->toBe('queued');

    (new RecheckCandidateCoverage($site->id))->handle(app(CandidateCoverage::class));

    $status = RecheckCandidateCoverage::status($site->id);
    expect($status['state'])->toBe('done')
        ->and($status['result'])->toBe(['checked' => 2, 'author' => 1, 'refresh' => 1, 'covered' => 0])
        ->and($twinned->fresh()->meta['coverage']['verdict'])->toBe('refresh')   // a stale "author" stamp is corrected
        ->and($fresh->fresh()->meta['coverage']['verdict'])->toBe('author');
});

it('records a failure on the state and forgets the state of a vanished site', function () {
    $site = Site::factory()->create();
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate, 'title' => 'x', 'body' => null, 'meta' => []]);
    $broken = Mockery::mock(CandidateCoverage::class);
    $broken->shouldReceive('stamp')->andThrow(new RuntimeException('embeddings down'));

    (new RecheckCandidateCoverage($site->id))->handle($broken);
    expect(RecheckCandidateCoverage::status($site->id))->toMatchArray(['state' => 'failed', 'error' => 'embeddings down']);

    Cache::put('coverage.recheck.gone', ['state' => 'queued', 'at' => now()->toIso8601String()], 60);
    (new RecheckCandidateCoverage('gone'))->handle(app(CandidateCoverage::class));
    expect(RecheckCandidateCoverage::status('gone')['state'])->toBe('idle');
});

it('is scheduled daily after the Search Console sync', function () {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn ($e) => str_contains((string) $e->command, 'launchpad:assess-coverage') && str_contains((string) $e->command, '--all') && str_contains((string) $e->command, '--execute'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('40 5 * * *');
});
