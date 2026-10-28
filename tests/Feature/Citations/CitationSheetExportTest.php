<?php

use App\Citations\CitationSheetExport;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sumppumpgurus.com']);
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create(['name' => 'Montclair office']);
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $this->location->id, 'business_name' => 'Sump Pump Gurus', 'address_1' => '50 South Park Street', 'address_2' => 'Suite 2',
        'city' => 'Montclair', 'state' => 'NJ', 'postal' => '07042', 'phone_primary' => '(862) 292-0566', 'website_url' => 'https://sumppumpgurus.com/montclair-nj/',
        'categories' => ['Plumbing', 'Waterproofing company'], 'hours' => [['day' => 'Mon–Fri', 'open' => '8:00', 'close' => '18:00'], ['day' => 'Sat', 'open' => '9:00', 'close' => '13:30']],
        'description_short' => 'Sump pump repair, installation and basement waterproofing in Montclair.',
    ]);
});

function sheetDir(string $name, array $attrs = []): Directory
{
    return Directory::factory()->create(array_merge(['name' => $name, 'scope' => DirectoryScope::National, 'is_submittable' => true, 'homepage_url' => 'https://'.strtolower($name).'.example/'], $attrs));
}

test('the tracker export carries the canonical NAP block and every directory with the scan\'s status, live URL, where to create one, and notes', function (): void {
    $live = sheetDir('Yelp');
    $missing = sheetDir('Hotfrog', ['submission_url' => 'https://hotfrog.example/add']);
    $mismatch = sheetDir('MapQuest');
    $never = sheetDir('Brownbook');
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $live->id, 'presence' => CitationPresence::PresentMatch, 'found_url' => 'https://yelp.example/biz/sump-pump-gurus-montclair', 'last_scanned_at' => '2026-10-20 10:00:00']);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $missing->id, 'presence' => CitationPresence::Absent, 'last_scanned_at' => '2026-10-20 10:00:00']);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->location->id, 'directory_id' => $mismatch->id, 'presence' => CitationPresence::PresentMismatch, 'found_url' => 'https://mapquest.example/l/123', 'mismatch_fields' => ['phone' => ['found' => '1', 'expected' => '2']], 'last_scanned_at' => '2026-10-20 10:00:00']);

    $data = app(CitationSheetExport::class)->forSite($this->site);
    expect($data['site'])->toBe(['brand' => 'Sump Pump Gurus', 'domain' => 'https://sumppumpgurus.com'])
        ->and($data['locations'])->toHaveCount(1);
    $loc = $data['locations'][0];
    expect($loc['name'])->toBe('Montclair office')->and($loc['has_profile'])->toBeTrue()
        ->and($loc['nap'])->toMatchArray(['business_name' => 'Sump Pump Gurus', 'street' => '50 South Park Street', 'suite' => 'Suite 2', 'city' => 'Montclair', 'state' => 'NJ', 'zip' => '07042',
            'phone' => '(862) 292-0566', 'website' => 'https://sumppumpgurus.com/montclair-nj/', 'categories' => 'Plumbing; Waterproofing company', 'hours' => 'Mon–Fri 8:00–18:00; Sat 9:00–13:30']);

    $rows = collect($loc['directories'])->keyBy('directory');
    expect($rows['Yelp']['status'])->toBe('Live')->and($rows['Yelp']['live_url'])->toBe('https://yelp.example/biz/sump-pump-gurus-montclair')->and($rows['Yelp']['notes'])->toContain('checked 2026-10-20')
        ->and($rows['MapQuest']['status'])->toBe('Live — NAP mismatch, fix')->and($rows['MapQuest']['notes'])->toContain('Phone')
        ->and($rows['Hotfrog']['status'])->toBe('Missing')->and($rows['Hotfrog']['live_url'])->toBeNull()->and($rows['Hotfrog']['notes'])->toContain('Create listing: https://hotfrog.example/add')
        ->and($rows['Brownbook']['status'])->toBe('Not scanned')->and($rows['Brownbook']['notes'])->toContain('Open site: https://brownbook.example/');

    // The CSV is the tracker tab's own layout: the NAP block, a blank line, the directory table in its columns.
    $csv = CitationSheetExport::csv($loc);
    expect($csv)->toStartWith("\"Master NAP Information\"\n\"Business / Location Name\",\"Sump Pump Gurus\"\n")
        ->toContain('"Phone Number","(862) 292-0566"')
        ->toContain('Directory,Status,"Live URL",Username/Email,Password,"Date Submitted","Verification Method",Notes')
        ->toContain('Yelp,Live,https://yelp.example/biz/sump-pump-gurus-montclair,,,,,');

    // The command: JSON for every office; CSV for one.
    $this->artisan('launchpad:citation-sheet --site='.$this->site->id)->expectsOutputToContain('"business_name": "Sump Pump Gurus"')->assertSuccessful();
    Location::factory()->for($this->site)->create(['name' => 'Hoboken office']);
    $this->artisan('launchpad:citation-sheet --site='.$this->site->id.' --format=csv')->expectsOutputToContain('one office at a time')->assertFailed();
    $this->artisan('launchpad:citation-sheet --site='.$this->site->id.' --format=csv --location='.$this->location->id)->expectsOutputToContain('Hotfrog,Missing')->assertSuccessful();
});

test('an office without a NAP profile still exports, falling back to the location and the site', function (): void {
    $bare = Location::factory()->for($this->site)->create(['name' => 'Trooper office', 'address' => '2753 West Main Street', 'phone' => '(484) 808-2225']);
    $loc = collect(app(CitationSheetExport::class)->forSite($this->site)['locations'])->firstWhere('name', 'Trooper office');
    expect($loc['has_profile'])->toBeFalse()
        ->and($loc['nap']['street'])->toBe('2753 West Main Street')
        ->and($loc['nap']['phone'])->toBe('(484) 808-2225')
        ->and($loc['nap']['website'])->toBe('https://sumppumpgurus.com');
});
