<?php

use App\Citations\CitationSheetExport;
use App\Citations\CitationWorkbook;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsBoard;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    $this->site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus', 'domain_url' => 'https://sumppumpgurus.com']);
    CurrentSite::set($this->site->id);
    SiteBranding::factory()->create(['site_id' => $this->site->id, 'logo_set' => ['url' => 'https://cdn.spg.example/logo.png']]);
    $this->montclair = Location::factory()->for($this->site)->create(['name' => 'Montclair office']);
    $this->trooper = Location::factory()->for($this->site)->create(['name' => 'Trooper office']);
    LocationNapProfile::factory()->for($this->site)->create([
        'location_id' => $this->montclair->id, 'business_name' => 'Sump Pump Gurus', 'address_1' => '50 South Park Street', 'address_2' => 'Suite 2',
        'city' => 'Montclair', 'state' => 'NJ', 'postal' => '07042', 'phone_primary' => '(862) 292-0566', 'website_url' => 'https://sumppumpgurus.com/montclair-nj/',
        'categories' => ['Plumbing'], 'hours' => ['mon' => ['open' => '08:00', 'close' => '18:00']], 'description_short' => 'Sump pump repair in Montclair.',
        'description_long' => 'The long description for directories that take one.', 'verification_email' => 'montclair@spg.example', 'photo_urls' => ['https://cdn.spg.example/a.jpg', 'https://cdn.spg.example/b.jpg'],
    ]);
});

/** @return array<string, list<list<mixed>>> sheet name → rows */
function workbookRows(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $out = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $out[$sheet->getName()] = $rows;
    }
    $reader->close();

    return $out;
}

test('the workbook has a Read me, an All offices NAP tab and one tab per office with the NAP block, assets and the tracker table carrying what the scan found', function (): void {
    $yelp = Directory::factory()->create(['name' => 'Yelp', 'scope' => DirectoryScope::National, 'is_submittable' => true, 'homepage_url' => 'https://yelp.example/']);
    $hotfrog = Directory::factory()->create(['name' => 'Hotfrog', 'scope' => DirectoryScope::National, 'is_submittable' => true, 'homepage_url' => 'https://hotfrog.example/', 'submission_url' => 'https://hotfrog.example/add']);
    $mapquest = Directory::factory()->create(['name' => 'MapQuest', 'scope' => DirectoryScope::National, 'is_submittable' => true, 'homepage_url' => 'https://mapquest.example/']);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->montclair->id, 'directory_id' => $yelp->id, 'presence' => CitationPresence::PresentMatch, 'found_url' => 'https://yelp.example/biz/spg-montclair', 'last_scanned_at' => '2026-10-20 10:00:00']);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->montclair->id, 'directory_id' => $hotfrog->id, 'presence' => CitationPresence::Absent, 'last_scanned_at' => '2026-10-20 10:00:00']);
    CitationStatus::factory()->for($this->site)->create(['location_id' => $this->montclair->id, 'directory_id' => $mapquest->id, 'presence' => CitationPresence::PresentMismatch, 'found_url' => 'https://mapquest.example/l/123', 'mismatch_fields' => ['phone' => ['found' => '1', 'expected' => '2']], 'last_scanned_at' => '2026-10-20 10:00:00']);

    $path = app(CitationWorkbook::class)->build($this->site);
    $sheets = workbookRows($path);
    @unlink($path);

    expect(array_keys($sheets))->toBe(['Read me', 'All offices NAP', 'Montclair office', 'Trooper office']);

    // Read me: the rules and the office index with counts.
    $readme = $sheets['Read me'];
    expect($readme[0][0])->toBe('Sump Pump Gurus — citation work order')
        ->and(collect($readme)->pluck(0)->implode("\n"))->toContain('No aggregators, data feeds or automation')->toContain('Our rescan is the acceptance test');
    $index = collect($readme)->first(fn (array $r): bool => ($r[0] ?? null) === 'Montclair office');
    expect($index)->toBe(['Montclair office', 'Montclair office', 3, 1, 1, 1, 0, 'montclair@spg.example']);

    // All offices NAP: one row per office; the office without a profile is flagged.
    $nap = $sheets['All offices NAP'];
    expect($nap[0][0])->toBe('Office')->and($nap[1])->toMatchArray([0 => 'Montclair office', 1 => 'Sump Pump Gurus', 2 => '50 South Park Street', 3 => 'Suite 2', 7 => '(862) 292-0566', 12 => 'The long description for directories that take one.', 13 => 'montclair@spg.example', 14 => 'https://cdn.spg.example/logo.png', 15 => 'https://cdn.spg.example/a.jpg https://cdn.spg.example/b.jpg', 16 => 'yes'])
        ->and($nap[2][0])->toBe('Trooper office')->and($nap[2][16])->toStartWith('NO');

    // The office tab: NAP block, blank line, the tracker table (first 8 columns = the CSV tracker) + scan context.
    $tab = $sheets['Montclair office'];
    expect($tab[0])->toBe(['Master NAP Information', 'Montclair office'])
        ->and(collect($tab)->first(fn (array $r): bool => ($r[0] ?? null) === 'Phone Number'))->toBe(['Phone Number', '(862) 292-0566'])
        ->and(collect($tab)->first(fn (array $r): bool => ($r[0] ?? null) === 'Logo URL'))->toBe(['Logo URL', 'https://cdn.spg.example/logo.png']);
    $headerAt = collect($tab)->search(fn (array $r): bool => ($r[0] ?? null) === 'Directory');
    expect($tab[$headerAt])->toBe(CitationWorkbook::COLUMNS)
        ->and(array_slice($tab[$headerAt], 0, 8))->toBe(CitationSheetExport::COLUMNS);
    $rows = collect(array_slice($tab, $headerAt + 1))->keyBy(0);
    expect($rows['Yelp'][1])->toBe('Live')->and($rows['Yelp'][2])->toBe('https://yelp.example/biz/spg-montclair')->and($rows['Yelp'][8])->toBe('https://yelp.example/biz/spg-montclair')
        ->and($rows['MapQuest'][1])->toBe('Live — NAP mismatch, fix')->and($rows['MapQuest'][2])->toBe('https://mapquest.example/l/123')->and($rows['MapQuest'][9])->toContain('Phone')
        ->and($rows['Hotfrog'][1])->toBe('Missing')->and($rows['Hotfrog'][2])->toBe('')->and($rows['Hotfrog'][10])->toBe('https://hotfrog.example/add')
        ->and($rows['Hotfrog'][11])->toContain('national')->and($rows['Hotfrog'][12])->toBe('https://hotfrog.example/')->and($rows['Hotfrog'][13])->toBe('2026-10-20');
});

test('tab names are Excel-safe and unique; the command writes the workbook; the board downloads it', function (): void {
    $wb = app(CitationWorkbook::class);
    expect($wb->tabNames([['name' => 'A/B: the [main] office?'], ['name' => 'A B the main office'], ['name' => str_repeat('x', 40)]]))
        ->toBe(['A B the main office', 'A B the main office (2)', str_repeat('x', 31)]);

    $out = sys_get_temp_dir().'/wb-'.uniqid().'.xlsx';
    $this->artisan('launchpad:citation-sheet --site='.$this->site->id.' --format=xlsx --out='.$out)->expectsOutputToContain('Workbook written')->assertSuccessful();
    expect(file_exists($out))->toBeTrue()->and(array_keys(workbookRows($out)))->toBe(['Read me', 'All offices NAP', 'Montclair office', 'Trooper office']);
    @unlink($out);

    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app(ActiveTenant::class)->set($this->site->id);
    Livewire::test(CitationsBoard::class)->assertSee('Download work order (.xlsx)')->call('downloadWorkbook')->assertFileDownloaded(CitationWorkbook::filename($this->site));
});
