<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function rpPage(Site $site, ContentStatus $status, string $title): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => $status,
        'title' => $title, 'slug' => Str::slug($title), 'last_publish_error' => $status === ContentStatus::PublishFailed ? 'HTTP 401' : null,
    ]);
}

it('resets stuck/failed publishes to approved and leaves live pages alone', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $failed = rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');
    $renderFailed = rpPage($site, ContentStatus::RenderFailed, 'Sump Pit Cleaning');
    $publishing = rpPage($site, ContentStatus::Publishing, 'Battery Backup');
    $live = rpPage($site, ContentStatus::Published, 'Homepage');

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG']);

    expect($failed->fresh()->status)->toBe(ContentStatus::Approved)
        ->and($failed->fresh()->last_publish_error)->toBeNull()
        ->and($renderFailed->fresh()->status)->toBe(ContentStatus::Approved)
        ->and($publishing->fresh()->status)->toBe(ContentStatus::Approved)
        ->and($live->fresh()->status)->toBe(ContentStatus::Published); // never touched
});

it('dry-run changes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $failed = rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--dry-run' => true]);

    expect($failed->fresh()->status)->toBe(ContentStatus::PublishFailed);
    expect(Artisan::output())->toContain('Dry run');
});

it('--flush-failed clears this tenant\'s dead failed jobs', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $failed = rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');
    $other = Site::factory()->create(['brand_name' => 'Other']);
    $otherFailed = rpPage($other, ContentStatus::PublishFailed, 'Other Page');

    // A dead failed_jobs row whose payload references the SPG content id, and one for the other tenant.
    DB::table('failed_jobs')->insert([
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{"data":{"command":"...'.$failed->id.'..."}}', 'exception' => 'HTTP 401', 'failed_at' => now()],
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{"data":{"command":"...'.$otherFailed->id.'..."}}', 'exception' => 'HTTP 401', 'failed_at' => now()],
    ]);

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--flush-failed' => true]);

    // Only SPG's dead job is cleared; the other tenant's failed job is untouched.
    expect(DB::table('failed_jobs')->where('payload', 'like', '%'.$failed->id.'%')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->where('payload', 'like', '%'.$otherFailed->id.'%')->count())->toBe(1);
});

it('reports nothing to do on a clean tenant', function () {
    $site = Site::factory()->create(['brand_name' => 'Clean']);
    rpPage($site, ContentStatus::Published, 'Live');

    Artisan::call('launchpad:reset-publish', ['site' => 'Clean']);

    expect(Artisan::output())->toContain('nothing stuck');
});

it('--reject pulls stuck items out of the pipeline with a reason (never publishes them)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $failed = rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');
    $publishing = rpPage($site, ContentStatus::Publishing, 'Battery Backup');
    $live = rpPage($site, ContentStatus::Published, 'Homepage');

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--reject' => true, '--reason' => 'Not launching these yet']);

    expect($failed->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($failed->fresh()->reject_reason)->toBe('Not launching these yet')
        ->and($failed->fresh()->last_publish_error)->toBeNull()
        ->and($publishing->fresh()->status)->toBe(ContentStatus::Rejected)
        ->and($live->fresh()->status)->toBe(ContentStatus::Published); // live page untouched
});

it('catches an approved page whose publish job is stuck in the queue (status still approved)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $queued = rpPage($site, ContentStatus::Approved, 'Sump Pump Repair');   // status approved, job stuck
    $cleanApproved = rpPage($site, ContentStatus::Approved, 'Clean Approved'); // no job → not stuck

    // A pending jobs row references the queued page (the "0 rendering/publishing, still approved" case).
    DB::table('jobs')->insert([
        'queue' => 'default', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp,
        'created_at' => now()->timestamp, 'payload' => '{"data":{"command":"...'.$queued->id.'..."}}',
    ]);

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--reject' => true]);

    expect($queued->fresh()->status)->toBe(ContentStatus::Rejected)          // pulled out of the pipeline
        ->and($cleanApproved->fresh()->status)->toBe(ContentStatus::Approved); // untouched — no stuck job
});

it('--flush-failed clears the tenant\'s pending AND dead jobs', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $failed = rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');

    DB::table('jobs')->insert([
        'queue' => 'default', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp,
        'created_at' => now()->timestamp, 'payload' => '{"data":{"command":"...'.$failed->id.'..."}}',
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => '{"data":{"command":"...'.$failed->id.'..."}}', 'exception' => 'HTTP 401', 'failed_at' => now(),
    ]);

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--flush-failed' => true]);

    expect(DB::table('jobs')->where('payload', 'like', '%'.$failed->id.'%')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->where('payload', 'like', '%'.$failed->id.'%')->count())->toBe(0);
});
