<?php

use App\Jobs\WarmLiveMetrics;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('it queues a warm per engine-eligible site, skips the rest, and prunes stale warm failures', function () {
    Queue::fake();

    $active = Site::factory()->create(['status' => 'active']);
    $building = Site::factory()->create(['status' => 'building']);
    $live = Site::factory()->create(['status' => 'live']);
    Site::factory()->create(['status' => 'suspended']);   // ineligible
    Site::factory()->create(['status' => 'onboarding']);  // ineligible

    // A stale benign warm failure a prior deploy left behind — the command should auto-clear it.
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => (string) json_encode(['displayName' => 'App\\Jobs\\WarmLiveMetrics', 'data' => ['command' => '']]),
        'exception' => 'MaxAttemptsExceeded', 'failed_at' => now(),
    ]);

    $this->artisan('launchpad:warm-live-metrics')->assertSuccessful();

    // One warm dispatched per eligible site (active/building/live), none for suspended/onboarding.
    Queue::assertPushed(WarmLiveMetrics::class, 3);
    foreach ([$active, $building, $live] as $s) {
        Queue::assertPushed(WarmLiveMetrics::class, fn (WarmLiveMetrics $j): bool => $j->siteId === $s->id);
    }

    expect(DB::table('failed_jobs')->count())->toBe(0); // benign warm noise cleared
});
