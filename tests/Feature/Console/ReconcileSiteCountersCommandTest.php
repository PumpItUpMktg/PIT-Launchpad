<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

it('recomputes drifted counters from the source of truth', function () {
    $site = Site::factory()->create();
    Content::factory()->count(3)->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::RenderFailed]);

    // Simulate drift — clobber the persisted columns directly, bypassing the observer.
    DB::table('sites')->where('id', $site->id)->update([
        'review_backlog_count' => 99,
        'render_failed_count' => 0,
        'publish_failed_count' => 42,
        'compromised_count' => 7,
    ]);

    $this->artisan('launchpad:reconcile-site-counters')->assertSuccessful();

    $s = Site::withoutGlobalScopes()->findOrFail($site->id);
    expect($s->review_backlog_count)->toBe(3)
        ->and($s->render_failed_count)->toBe(1)
        ->and($s->publish_failed_count)->toBe(0)
        ->and($s->compromised_count)->toBe(0);
});

it('reconciles only the named site with --site', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    Content::factory()->create(['site_id' => $b->id, 'status' => ContentStatus::NeedsReview]);
    DB::table('sites')->whereIn('id', [$a->id, $b->id])->update(['review_backlog_count' => 50]);

    $this->artisan('launchpad:reconcile-site-counters', ['--site' => $b->id])->assertSuccessful();

    expect(Site::withoutGlobalScopes()->findOrFail($b->id)->review_backlog_count)->toBe(1)
        ->and(Site::withoutGlobalScopes()->findOrFail($a->id)->review_backlog_count)->toBe(50); // untouched
});

it('fails cleanly for an unknown site id', function () {
    $this->artisan('launchpad:reconcile-site-counters', ['--site' => 'nope'])->assertFailed();
});
