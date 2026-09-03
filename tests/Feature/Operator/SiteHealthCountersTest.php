<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Site;

/** Reload the site free of the VisibleSiteScope so the assertion is deterministic without an auth actor. */
function counters(Site $site): Site
{
    return Site::withoutGlobalScopes()->findOrFail($site->id);
}

it('populates the persisted counters when content is created in a tracked status', function () {
    $site = Site::factory()->create();
    Content::factory()->count(2)->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::RenderFailed]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::PublishFailed]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate]); // untracked — no bump

    $s = counters($site);
    expect($s->review_backlog_count)->toBe(2)
        ->and($s->render_failed_count)->toBe(1)
        ->and($s->publish_failed_count)->toBe(1);
});

it('moves the counts when a content transitions between statuses', function () {
    $site = Site::factory()->create();
    $c = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::RenderFailed]);
    expect(counters($site)->render_failed_count)->toBe(1);

    $c->forceFill(['status' => ContentStatus::Approved])->save();
    expect(counters($site)->render_failed_count)->toBe(0);

    $c->forceFill(['status' => ContentStatus::NeedsReview])->save();
    $s = counters($site);
    expect($s->review_backlog_count)->toBe(1)->and($s->render_failed_count)->toBe(0);
});

it('drops the count on soft delete and restores it (matching the withCount soft-delete scope)', function () {
    $site = Site::factory()->create();
    $c = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::PublishFailed]);
    expect(counters($site)->publish_failed_count)->toBe(1);

    $c->delete(); // soft
    expect(counters($site)->publish_failed_count)->toBe(0);

    $c->restore();
    expect(counters($site)->publish_failed_count)->toBe(1);
});

it('tracks compromised connections on the site counter', function () {
    $site = Site::factory()->create();
    // Distinct providers — connections are unique on (site_id, provider), so a shared site needs two.
    $conn = Connection::factory()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword, 'compromised' => true]);
    expect(counters($site)->compromised_count)->toBe(1);

    $conn->forceFill(['compromised' => false, 'compromised_reason' => null])->save();
    expect(counters($site)->compromised_count)->toBe(0);

    $second = Connection::factory()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::Ghl, 'compromised' => true]);
    expect(counters($site)->compromised_count)->toBe(1);

    $second->delete();
    expect(counters($site)->compromised_count)->toBe(0);
});

it('does not bump another tenant when content is created (counters are per-site)', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    Content::factory()->create(['site_id' => $a->id, 'status' => ContentStatus::NeedsReview]);

    expect(counters($a)->review_backlog_count)->toBe(1)
        ->and(counters($b)->review_backlog_count)->toBe(0);
});
