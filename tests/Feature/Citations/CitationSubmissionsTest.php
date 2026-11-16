<?php

use App\Citations\CitationSheetExport;
use App\Citations\CitationSubmissions;
use App\Enums\CitationEventType;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
use App\Enums\DirectoryScope;
use App\Models\CitationEvent;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create(['brand_name' => 'ACME Plumbing', 'domain_url' => 'https://acme.example']);
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create(['name' => 'Bedminster office']);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $this->location->id, 'business_name' => 'ACME Plumbing', 'categories' => null]);
    $this->yelp = Directory::factory()->create(['name' => 'Yelp', 'domain' => 'yelp.com', 'scope' => DirectoryScope::National, 'is_submittable' => true]);
    $this->bbb = Directory::factory()->create(['name' => 'Better Business Bureau', 'domain' => 'bbb.org', 'scope' => DirectoryScope::National, 'is_submittable' => true]);
    $this->angi = Directory::factory()->create(['name' => 'Angi', 'domain' => 'angi.com', 'scope' => DirectoryScope::National, 'is_submittable' => true]);
    $this->foursquare = Directory::factory()->create(['name' => 'Foursquare', 'domain' => 'foursquare.com', 'scope' => DirectoryScope::National, 'is_submittable' => true]);
});

function trackerCsv(array $rows): string
{
    $h = fopen('php://temp', 'r+');
    fputcsv($h, ['Master NAP Information']);
    fputcsv($h, ['Business / Location Name', 'ACME Plumbing']);
    fputcsv($h, []);
    fputcsv($h, CitationSheetExport::COLUMNS);
    foreach ($rows as $r) {
        fputcsv($h, $r);
    }
    rewind($h);

    return "\xEF\xBB\xBF".stream_get_contents($h);   // a Sheets/Excel export carries a BOM
}

test('mark submitted flips the lifecycle, keeps the reported URL, and creates the row for a directory the scan never reached', function (): void {
    $status = CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $this->yelp->id, 'presence' => CitationPresence::Absent, 'found_url' => null]);

    $updated = (new CitationSubmissions)->markSubmitted($this->location, $this->yelp->id, 'https://www.yelp.com/biz/acme-plumbing-bedminster');

    expect($updated->id)->toBe($status->id)
        ->and($updated->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($updated->source)->toBe(CitationSource::Manual)
        ->and($updated->submitted_at)->not->toBeNull()
        ->and($updated->found_url)->toBe('https://www.yelp.com/biz/acme-plumbing-bedminster')
        ->and($updated->presence)->toBe(CitationPresence::Absent);   // presence is the scanner's — untouched

    $event = CitationEvent::query()->where('location_id', $this->location->id)->where('directory_id', $this->yelp->id)->latest('occurred_at')->first();
    expect($event->event_type)->toBe(CitationEventType::Submitted)
        ->and($event->meta['live_url'])->toBe('https://www.yelp.com/biz/acme-plumbing-bedminster');

    // No status row yet (never scanned) → one is created, presence unknown, lifecycle submitted.
    $fresh = (new CitationSubmissions)->markSubmitted($this->location, $this->bbb->id, 'not a url');
    expect($fresh->presence)->toBe(CitationPresence::Unknown)
        ->and($fresh->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($fresh->found_url)->toBeNull()   // a non-URL is treated as blank
        ->and($fresh->site_id)->toBe($this->site->id);
});

test('the filled tracker comes back: dated or URL rows submit, rejected rows reject with the notes, untouched rows skip, unknown names are listed, and a re-import changes nothing', function (): void {
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $this->yelp->id, 'presence' => CitationPresence::Absent]);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $this->angi->id, 'presence' => CitationPresence::Absent]);

    $csv = trackerCsv([
        ['Yelp', 'Missing', '', 'acme-bedminster@example.com', 'pw', '2026-11-18', 'Email', 'created, awaiting approval'],
        ['bbb.org', 'Missing', 'https://www.bbb.org/us/nj/bedminster/profile/plumber/acme-0221-1', '', '', '', '', ''],   // matched by domain, URL only
        ['Angi', 'Rejected', '', '', '', '2026-11-18', '', 'Angi requires a paid plan for a second location'],
        ['Foursquare', 'Missing', '', '', '', '', '', ''],   // the VA did not get to it
        ['Nowhere Directory', 'Missing', '', '', '', '2026-11-18', '', ''],
    ]);

    $r = (new CitationSubmissions)->importTracker($this->location, $csv);

    expect($r)->toMatchArray(['submitted' => 2, 'rejected' => 1, 'already' => 0, 'skipped' => 1])
        ->and($r['unknown'])->toBe(['Nowhere Directory']);

    $by = fn (Directory $d): CitationStatus => CitationStatus::query()->where('location_id', $this->location->id)->where('directory_id', $d->id)->firstOrFail();
    expect($by($this->yelp)->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($by($this->yelp)->source)->toBe(CitationSource::Va)
        ->and($by($this->bbb)->lifecycle)->toBe(CitationLifecycleState::Submitted)
        ->and($by($this->bbb)->found_url)->toBe('https://www.bbb.org/us/nj/bedminster/profile/plumber/acme-0221-1')
        ->and($by($this->angi)->lifecycle)->toBe(CitationLifecycleState::Rejected)
        ->and($by($this->angi)->reject_reason)->toBe('Angi requires a paid plan for a second location')
        ->and(CitationStatus::query()->where('location_id', $this->location->id)->where('directory_id', $this->foursquare->id)->exists())->toBeFalse();

    // The same sheet again: everything is already recorded; nothing moves and no second event is written.
    $events = CitationEvent::query()->where('location_id', $this->location->id)->count();
    $again = (new CitationSubmissions)->importTracker($this->location, $csv);
    expect($again)->toMatchArray(['submitted' => 0, 'rejected' => 0, 'already' => 3, 'skipped' => 1])
        ->and(CitationEvent::query()->where('location_id', $this->location->id)->count())->toBe($events);
});

test('the export round-trips: the CSV this page downloads is accepted back unchanged', function (): void {
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $this->yelp->id, 'presence' => CitationPresence::Absent]);
    $csv = CitationSheetExport::csv(app(CitationSheetExport::class)->forLocation($this->site, $this->location));

    expect((new CitationSubmissions)->importTracker($this->location, $csv))->toMatchArray(['submitted' => 0, 'rejected' => 0, 'already' => 0, 'unknown' => []]);
});
