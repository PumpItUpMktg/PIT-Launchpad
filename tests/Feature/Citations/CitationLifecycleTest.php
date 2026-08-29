<?php

use App\Citations\CitationLifecycle;
use App\Enums\CitationEventType;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Models\CitationEvent;
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

function lifecycleStatus(mixed $ctx, CitationPresence $presence, CitationLifecycleState $lifecycle, ?Carbon $scannedAt = null): CitationStatus
{
    return CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id,
        'directory_id' => $ctx->directory->id,
        'presence' => $presence,
        'lifecycle' => $lifecycle,
        'last_scanned_at' => $scannedAt ?? Carbon::now(),
    ]);
}

test('submit moves the lifecycle axis and resets the verification clock', function (): void {
    $status = lifecycleStatus($this, CitationPresence::Absent, CitationLifecycleState::None);

    $this->lifecycle->submit($status);

    $status->refresh();
    expect($status->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($status->presence)->toBe(CitationPresence::Absent) // presence untouched — the axes are independent
        ->and($status->submitted_at)->not->toBeNull()
        ->and($status->verification_cycles)->toBe(0)
        ->and(CitationEvent::query()->where('event_type', CitationEventType::Submitted)->exists())->toBeTrue();
});

test('a submission the scan now finds verifies', function (): void {
    // The scanner already ran this pass and set presence to a real listing.
    $status = lifecycleStatus($this, CitationPresence::PresentMatch, CitationLifecycleState::Submitted, Carbon::now());

    $this->lifecycle->verify($this->location, Carbon::now()->subMinute());

    expect($status->refresh()->lifecycle)->toBe(CitationLifecycleState::Verified)
        ->and(CitationEvent::query()->where('event_type', CitationEventType::Verified)->exists())->toBeTrue();
});

test('a submission the scan cannot confirm stalls at the threshold', function (): void {
    config(['launchpad.citations.verification_cycle_threshold' => 2]);
    // Not present this pass (last scan is stale), so it is never confirmed.
    $status = lifecycleStatus($this, CitationPresence::Absent, CitationLifecycleState::Submitted, Carbon::now()->subDay());

    $this->lifecycle->verify($this->location, Carbon::now()); // cycle 1 → still submitted
    expect($status->refresh()->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($status->verification_cycles)->toBe(1);

    $this->lifecycle->verify($this->location, Carbon::now()); // cycle 2 → stalled
    expect($status->refresh()->lifecycle)->toBe(CitationLifecycleState::Stalled)
        ->and(CitationEvent::query()->where('event_type', CitationEventType::Stalled)->exists())->toBeTrue();
});

test('a citation issued in too many work orders stalls', function (): void {
    config(['launchpad.citations.work_order_stall_threshold' => 2]);
    $status = lifecycleStatus($this, CitationPresence::Absent, CitationLifecycleState::None);

    $this->lifecycle->recordWorkOrderIssued($status);
    expect($status->refresh()->lifecycle)->toBe(CitationLifecycleState::None);

    $this->lifecycle->recordWorkOrderIssued($status);
    expect($status->refresh()->lifecycle)->toBe(CitationLifecycleState::Stalled)
        ->and($status->work_order_count)->toBe(2);
});

test('reject moves the lifecycle axis with a reason', function (): void {
    $status = lifecycleStatus($this, CitationPresence::Absent, CitationLifecycleState::Submitted);

    $this->lifecycle->reject($status, 'duplicate account');

    expect($status->refresh()->lifecycle)->toBe(CitationLifecycleState::Rejected)
        ->and($status->reject_reason)->toBe('duplicate account');
});
