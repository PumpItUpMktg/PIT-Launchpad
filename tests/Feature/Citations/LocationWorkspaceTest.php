<?php

use App\Citations\Ui\LocationWorkspace;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\TenantDirectoryExclusion;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $this->location->id, 'categories' => null]);
    $this->workspace = new LocationWorkspace;
});

function wsDir(string $name, array $attrs = []): Directory
{
    return Directory::factory()->create(array_merge(['name' => $name, 'scope' => DirectoryScope::National, 'is_submittable' => true], $attrs));
}

function wsStatus(mixed $ctx, Directory $dir, CitationPresence $presence, CitationLifecycleState $lifecycle = CitationLifecycleState::None, ?array $mismatch = null): void
{
    CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id, 'directory_id' => $dir->id,
        'presence' => $presence, 'lifecycle' => $lifecycle, 'mismatch_fields' => $mismatch,
    ]);
}

test('rows are ordered mismatch, then missing, then live, with the stat strip', function (): void {
    $live = wsDir('LiveDir');
    $missing = wsDir('MissingDir');
    $mismatch = wsDir('MismatchDir');
    wsStatus($this, $live, CitationPresence::PresentMatch);
    wsStatus($this, $missing, CitationPresence::Absent);
    wsStatus($this, $mismatch, CitationPresence::PresentMismatch, mismatch: ['phone' => ['found' => '1', 'expected' => '2']]);

    $result = $this->workspace->forLocation($this->location);

    expect(collect($result['rows'])->pluck('directoryName')->all())->toBe(['MismatchDir', 'MissingDir', 'LiveDir'])
        ->and($result['stats']['live'])->toBe(1)
        ->and($result['stats']['mismatch'])->toBe(1)
        ->and($result['stats']['missing'])->toBe(1)
        ->and($result['stats']['submittable_missing'])->toBe(1);
    expect(collect($result['rows'])->firstWhere('directoryName', 'MismatchDir')->napMatchSummary)->toBe('Phone');
});

test('excluded directories are hidden by default and shown as Not relevant when asked', function (): void {
    $dir = wsDir('ExcludedDir');
    TenantDirectoryExclusion::factory()->for($this->site)->create(['directory_id' => $dir->id]);

    expect(collect($this->workspace->forLocation($this->location)['rows'])->pluck('directoryName'))->not->toContain('ExcludedDir');

    $withHidden = $this->workspace->forLocation($this->location, includeNotRelevant: true);
    $row = collect($withHidden['rows'])->firstWhere('directoryName', 'ExcludedDir');
    expect($row->chip['label'])->toBe('Not relevant')->and($row->eligible)->toBeFalse();
});

test('within the missing group, submittable and local sort first', function (): void {
    $localSubmittable = Directory::factory()->create(['name' => 'ZZ Local', 'scope' => DirectoryScope::State, 'geo_value' => 'NJ', 'is_submittable' => true]);
    $nationalSubmittable = wsDir('AA National');
    LocationNapProfile::query()->where('location_id', $this->location->id)->update(['state' => 'NJ']);
    wsStatus($this, $localSubmittable, CitationPresence::Absent);
    wsStatus($this, $nationalSubmittable, CitationPresence::Absent);

    $rows = collect($this->workspace->forLocation($this->location)['rows'])->pluck('directoryName')->all();

    // Both submittable + missing; local (ZZ) sorts before national (AA) despite the name order.
    expect($rows)->toBe(['ZZ Local', 'AA National']);
});
