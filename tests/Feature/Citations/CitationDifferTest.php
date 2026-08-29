<?php

use App\Citations\CitationDiffer;
use App\Enums\CitationEventType;
use App\Enums\CitationPresence;
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

function citationStatus(mixed $ctx, CitationPresence $presence): CitationStatus
{
    return CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id,
        'directory_id' => Directory::factory()->create()->id,
        'presence' => $presence,
    ]);
}

test('a newly correct listing is discovered and counts as new', function (): void {
    $status = citationStatus($this, CitationPresence::PresentMatch);

    $buckets = $this->differ->record($this->location, $this->run, []); // nothing before

    expect($buckets['new'])->toBe(1);
    expect(CitationEvent::query()->where('directory_id', $status->directory_id)->where('event_type', CitationEventType::Discovered)->exists())->toBeTrue();
});

test('a mismatch turning correct is a fix', function (): void {
    $status = citationStatus($this, CitationPresence::PresentMatch);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationPresence::PresentMismatch]);

    expect($buckets['fixed'])->toBe(1)
        ->and($buckets['new'])->toBe(0);
});

test('a correct listing turning mismatch regresses', function (): void {
    $status = citationStatus($this, CitationPresence::PresentMismatch);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationPresence::PresentMatch]);

    expect($buckets['regressed'])->toBe(1);
    expect(CitationEvent::query()->where('event_type', CitationEventType::Regressed)->exists())->toBeTrue();
});

test('a correct listing turning absent is lost', function (): void {
    $status = citationStatus($this, CitationPresence::Absent);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationPresence::PresentMatch]);

    expect($buckets['lost'])->toBe(1);
});

test('a stable presence produces no event', function (): void {
    $status = citationStatus($this, CitationPresence::PresentMatch);

    $buckets = $this->differ->record($this->location, $this->run, [(string) $status->directory_id => CitationPresence::PresentMatch]);

    expect(array_sum($buckets))->toBe(0)
        ->and(CitationEvent::query()->count())->toBe(0);
});
