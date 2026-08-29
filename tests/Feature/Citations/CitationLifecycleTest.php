<?php

use App\Citations\CitationLifecycle;
use App\Enums\CitationEventType;
use App\Enums\CitationState;
use App\Models\CitationEvent;
use App\Models\CitationFoundDomain;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    $this->directory = Directory::factory()->create(['domain' => 'yelp.com']);
    $this->lifecycle = new CitationLifecycle;
});

function lifecycleStatus(mixed $ctx, CitationState $state, ?array $mismatch = null): CitationStatus
{
    return CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id,
        'directory_id' => $ctx->directory->id,
        'state' => $state,
        'mismatch_fields' => $mismatch,
    ]);
}

test('submit records the submission and resets the verification clock', function (): void {
    $status = lifecycleStatus($this, CitationState::NotListed);

    $this->lifecycle->submit($status);

    $status->refresh();
    expect($status->state)->toBe(CitationState::Submitted)
        ->and($status->submitted_at)->not->toBeNull()
        ->and($status->verification_cycles)->toBe(0)
        ->and(CitationEvent::query()->where('event_type', CitationEventType::Submitted)->exists())->toBeTrue();
});

test('a found submission verifies to live, or fixed when it was a correction', function (): void {
    // A new-listing submission (no mismatch) and a correction (mismatch), each on its own directory, both found.
    $bbb = Directory::factory()->create(['domain' => 'bbb.org']);
    $newListing = lifecycleStatus($this, CitationState::Submitted);
    $correction = CitationStatus::factory()->for($this->site)->create([
        'location_id' => $this->location->id, 'directory_id' => $bbb->id,
        'state' => CitationState::Submitted, 'mismatch_fields' => ['phone' => ['found' => '1', 'expected' => '2']],
    ]);
    foreach ([$this->directory->id, $bbb->id] as $dirId) {
        CitationFoundDomain::factory()->create([
            'site_id' => $this->site->id, 'location_id' => $this->location->id,
            'directory_id' => $dirId, 'last_seen_at' => Carbon::now(),
        ]);
    }

    $this->lifecycle->verify($this->location, Carbon::now()->subMinute());

    expect($newListing->refresh()->state)->toBe(CitationState::Live)
        ->and($correction->refresh()->state)->toBe(CitationState::Fixed);
});

test('a submission the scan cannot confirm flips to unverified at the threshold', function (): void {
    config(['launchpad.citations.verification_cycle_threshold' => 2]);
    $status = lifecycleStatus($this, CitationState::Submitted); // no found-domain row → never seen

    $this->lifecycle->verify($this->location, Carbon::now()); // cycle 1 → pending
    expect($status->refresh()->state)->toBe(CitationState::PendingVerification);

    $this->lifecycle->verify($this->location, Carbon::now()); // cycle 2 → unverified
    expect($status->refresh()->state)->toBe(CitationState::Unverified)
        ->and(CitationEvent::query()->where('event_type', CitationEventType::Unverified)->exists())->toBeTrue();
});

test('a citation issued in too many work orders stalls', function (): void {
    config(['launchpad.citations.work_order_stall_threshold' => 2]);
    $status = lifecycleStatus($this, CitationState::NotListed);

    $this->lifecycle->recordWorkOrderIssued($status);
    expect($status->refresh()->state)->toBe(CitationState::NotListed);

    $this->lifecycle->recordWorkOrderIssued($status);
    expect($status->refresh()->state)->toBe(CitationState::Stalled)
        ->and($status->work_order_count)->toBe(2);
});

test('reject records the rejection with a reason', function (): void {
    $status = lifecycleStatus($this, CitationState::Submitted);

    $this->lifecycle->reject($status, 'duplicate account');

    expect($status->refresh()->state)->toBe(CitationState::Rejected)
        ->and($status->reject_reason)->toBe('duplicate account');
});
