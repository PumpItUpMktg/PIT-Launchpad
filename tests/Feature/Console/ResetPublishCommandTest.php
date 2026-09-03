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

it('--to-candidate moves stuck blog posts back to candidate (pages stay approved) and flushes their jobs', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    // A blog post queued-to-publish (approved) with a stuck job, and a stuck page.
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
        'title' => 'Why Your Home Sewer Line Needs Inspection', 'slug' => 'sewer-inspection',
    ]);
    $page = rpPage($site, ContentStatus::PublishFailed, 'Cranford Sewer Repair');
    DB::table('jobs')->insert([
        'queue' => 'default', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp,
        'created_at' => now()->timestamp, 'payload' => '{"data":{"command":"...'.$post->id.'..."}}',
    ]);

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--to-candidate' => true]);

    expect($post->fresh()->status)->toBe(ContentStatus::Candidate)        // post → funnel
        ->and($page->fresh()->status)->toBe(ContentStatus::Approved)       // page has no candidate lane
        ->and(DB::table('jobs')->where('payload', 'like', '%'.$post->id.'%')->count())->toBe(0); // job flushed
});

it('--to-candidate recalls an approved post with NO lingering job row (worker already dropped it)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    // Queued-to-publish (approved) but its job was consumed/dropped by the dead worker — no jobs row.
    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
        'title' => 'Sewer System Changes Homeowners Should Know', 'slug' => 'sewer-changes',
    ]);

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--to-candidate' => true]);

    expect($post->fresh()->status)->toBe(ContentStatus::Candidate); // recalled even without a job row
});

it('rejects using both --reject and --to-candidate together', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');

    $code = Artisan::call('launchpad:reset-publish', ['site' => 'SPG', '--reject' => true, '--to-candidate' => true]);

    expect($code)->toBe(1);
    expect(Artisan::output())->toContain('not both');
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

it('recomputes the persisted site health counters after the bulk reset (which bypasses the observer)', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    rpPage($site, ContentStatus::PublishFailed, 'Sump Pump Repair');
    rpPage($site, ContentStatus::RenderFailed, 'Sump Pit Cleaning');

    // The observer maintained these on create; the bulk whereIn()->update() below skips it, so the
    // command must recompute — else they'd drift to a stale non-zero after the rows leave those statuses.
    $before = Site::withoutGlobalScopes()->findOrFail($site->id);
    expect($before->publish_failed_count)->toBe(1)->and($before->render_failed_count)->toBe(1);

    Artisan::call('launchpad:reset-publish', ['site' => 'SPG']);

    $after = Site::withoutGlobalScopes()->findOrFail($site->id);
    expect($after->publish_failed_count)->toBe(0)->and($after->render_failed_count)->toBe(0);
});
