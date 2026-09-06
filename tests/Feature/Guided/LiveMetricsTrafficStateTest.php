<?php

use App\Enums\JobStatus;
use App\Guided\LiveMetrics;
use App\Integrations\Analytics\PageTrafficProvider;
use App\JobCapture\Metrics\JobMetrics;
use App\Models\Content;
use App\Models\Job;
use App\Models\Site;

/**
 * A configurable cache-only traffic stub: `connected` is true, and `sessionsCachedState` returns whatever
 * the test sets — so the render can be driven through each (warmed count / warmed-empty / cold-miss) state.
 */
function stubTraffic(array $state): PageTrafficProvider
{
    $stub = new class($state) implements PageTrafficProvider
    {
        /** @param array{sessions: ?int, warmed: bool} $state */
        public function __construct(private array $state) {}

        public function connected(Site $site): bool
        {
            return true;
        }

        public function sessions(Site $site, string $path, int $days = 28): ?int
        {
            return $this->state['sessions'];
        }

        public function sessionsCached(Site $site, string $path, int $days = 28): ?int
        {
            return $this->state['sessions'];
        }

        public function sessionsCachedState(Site $site, string $path, int $days = 28): array
        {
            return $this->state;
        }

        public function refresh(Site $site, string $path, int $days = 28): ?int
        {
            return $this->state['sessions'];
        }
    };

    app()->instance(PageTrafficProvider::class, $stub);

    return $stub;
}

it('renders "Refreshing…" only for a genuine cold miss (never warmed)', function () {
    stubTraffic(['sessions' => null, 'warmed' => false]);
    $site = Site::factory()->create();
    $page = Content::factory()->create(['site_id' => $site->id, 'slug' => 'x']);

    $traffic = app(LiveMetrics::class)->for($page->fresh(), liveTraffic: false)['traffic'];

    expect($traffic)->toBe(['sessions' => null, 'pending' => 'Refreshing…']);
});

it('renders an honest "No traffic yet" for a warmed page GA4 has no data for', function () {
    stubTraffic(['sessions' => null, 'warmed' => true]);
    $site = Site::factory()->create();
    $page = Content::factory()->create(['site_id' => $site->id, 'slug' => 'x']);

    $traffic = app(LiveMetrics::class)->for($page->fresh(), liveTraffic: false)['traffic'];

    expect($traffic)->toBe(['sessions' => null, 'pending' => 'No traffic yet']);
});

it('renders the warmed session count when present', function () {
    stubTraffic(['sessions' => 42, 'warmed' => true]);
    $site = Site::factory()->create();
    $page = Content::factory()->create(['site_id' => $site->id, 'slug' => 'x']);

    $traffic = app(LiveMetrics::class)->for($page->fresh(), liveTraffic: false)['traffic'];

    expect($traffic)->toBe(['sessions' => 42, 'pending' => null]);
});

it('applies the same warmed-vs-miss distinction on the jobs board', function () {
    $site = Site::factory()->create();
    $job = Job::factory()->create(['site_id' => $site->id, 'status' => JobStatus::Published]);

    stubTraffic(['sessions' => null, 'warmed' => true]);
    expect(app(JobMetrics::class)->for($job->fresh(), cacheOnly: true, liveTraffic: false)['traffic']['pending'])->toBe('No traffic yet');

    stubTraffic(['sessions' => null, 'warmed' => false]);
    expect(app(JobMetrics::class)->for($job->fresh(), cacheOnly: true, liveTraffic: false)['traffic']['pending'])->toBe('Refreshing…');
});
