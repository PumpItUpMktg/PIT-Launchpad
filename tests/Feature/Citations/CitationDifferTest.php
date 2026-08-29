<?php

use App\Citations\CitationDiffer;
use App\Enums\CitationEventType;
use App\Enums\CitationState;
use App\Models\CitationEvent;
use App\Models\CitationScanRun;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    $this->run = CitationScanRun::factory()->for($this->site)->create(['location_id' => $this->location->id]);
    $this->differ = new CitationDiffer;
});

function citationStatus(mixed $ctx, CitationState $state, int $unresolved = 0): CitationStatus
{
    return CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id,
        'directory_id' => Directory::factory()->create()->id,
        'state' => $state,
        'unresolved_scans' => $unresolved,
    ]);
}

test('a newly covered directory is discovered and counts as new', function (): void {
    $status = citationStatus($this, CitationState::ListedCorrect);

    $buckets = $this->differ->record($this->location, $this->run, []); // nothing before

    expect($buckets['new'])->toBe(1);
    expect(CitationEvent::query()->where('directory_id', $status->directory_id)->where('event_type', CitationEventType::Discovered)->exists())->toBeTrue();
});

test('needs_fix turning covered is a fix', function (): void {
    $status = citationStatus($this, CitationState::ListedCorrect);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationState::NeedsFix]);

    expect($buckets['fixed'])->toBe(1)
        ->and($buckets['new'])->toBe(0);
});

test('a covered listing turning needs_fix regresses', function (): void {
    $status = citationStatus($this, CitationState::NeedsFix);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationState::ListedCorrect]);

    expect($buckets['regressed'])->toBe(1);
    expect(CitationEvent::query()->where('event_type', CitationEventType::Regressed)->exists())->toBeTrue();
});

test('a covered listing turning not_listed is lost', function (): void {
    $status = citationStatus($this, CitationState::NotListed);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationState::ListedCorrect]);

    expect($buckets['lost'])->toBe(1);
});

test('a gap that persists past the threshold raises exactly one stalled event', function (): void {
    config(['launchpad.citations.stalled_scan_threshold' => 2]);
    $status = citationStatus($this, CitationState::NotListed, unresolved: 1); // one scan already

    // No covered-boundary change (still not_listed) → prior == current.
    $prior = [(string) $status->directory_id => CitationState::NotListed];
    $this->differ->record($this->location, $this->run, $prior); // → count 2 == threshold → stalled
    $this->differ->record($this->location, $this->run, $prior); // → count 3, no new stalled

    expect(CitationEvent::query()->where('event_type', CitationEventType::Stalled)->count())->toBe(1)
        ->and($status->refresh()->unresolved_scans)->toBe(3);
});

test('a resolved gap resets the unresolved counter', function (): void {
    $status = citationStatus($this, CitationState::ListedCorrect, unresolved: 3);

    $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationState::ListedCorrect]);

    expect($status->refresh()->unresolved_scans)->toBe(0);
});
