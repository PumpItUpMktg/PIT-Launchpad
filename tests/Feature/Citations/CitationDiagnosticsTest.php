<?php

use App\Citations\CitationDiagnostics;
use App\Enums\UserRole;
use App\Filament\Pages\Citations\CitationsBoard;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoException;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** @param list<array{position:int,url:string,domain:string}> $organic */
function diagDfs(array $organic): DataForSeoClient
{
    return new class($organic) extends DataForSeoClient
    {
        /** @param list<array{position:int,url:string,domain:string}> $organic */
        public function __construct(private array $organic)
        {
            // no HTTP client needed for the double
        }

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            return $this->organic;
        }
    };
}

function throwingDfs(): DataForSeoClient
{
    return new class extends DataForSeoClient
    {
        public function __construct()
        {
            // no HTTP client needed for the double
        }

        public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
        {
            throw new DataForSeoException('DataForSEO HTTP 401', 401, fatal: true);
        }
    };
}

function diagLocation(): Location
{
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $location = Location::factory()->for($site)->create(['name' => 'Bedminster']);
    LocationNapProfile::factory()->for($site)->create([
        'location_id' => $location->id,
        'business_name' => 'ACME Plumbing',
        'city' => 'Clifton',
        'state' => 'NJ',
        'phone_primary' => '973-111-1111',
    ]);

    return $location;
}

beforeEach(function (): void {
    config(['services.dataforseo.login' => 'user', 'services.dataforseo.password' => 'pass']);
});

test('a healthy path reports directory hits and a healthy cause', function (): void {
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    $location = diagLocation();
    app()->instance(DataForSeoClient::class, diagDfs([
        ['position' => 1, 'url' => 'https://www.yelp.com/biz/acme', 'domain' => 'www.yelp.com'],
        ['position' => 2, 'url' => 'https://acme.example', 'domain' => 'acme.example'],
    ]));

    $report = app(CitationDiagnostics::class)->forLocation($location);

    expect($report->dfsConfigured)->toBeTrue()
        ->and($report->dfsOk)->toBeTrue()
        ->and($report->organicRows)->toBe(2)
        ->and($report->directoryHits)->toBe(['yelp.com'])
        ->and($report->severity())->toBe('success')
        ->and($report->likelyCause())->toContain('healthy');
});

test('missing DataForSEO credentials are the flagged cause', function (): void {
    config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    $location = diagLocation();

    $report = app(CitationDiagnostics::class)->forLocation($location);

    expect($report->dfsConfigured)->toBeFalse()
        ->and($report->dfsOk)->toBeNull()
        ->and($report->severity())->toBe('danger')
        ->and($report->likelyCause())->toContain('credentials are not set');
});

test('a failing DataForSEO call is captured, not thrown', function (): void {
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    $location = diagLocation();
    app()->instance(DataForSeoClient::class, throwingDfs());

    $report = app(CitationDiagnostics::class)->forLocation($location);

    expect($report->dfsOk)->toBeFalse()
        ->and($report->dfsError)->toContain('401')
        ->and($report->severity())->toBe('danger')
        ->and($report->likelyCause())->toContain('DataForSEO call failed');
});

test('an empty directory catalog is the flagged cause', function (): void {
    $location = diagLocation();
    app()->instance(DataForSeoClient::class, diagDfs([]));

    $report = app(CitationDiagnostics::class)->forLocation($location);

    expect($report->activeDirectories)->toBe(0)
        ->and($report->likelyCause())->toContain('catalog is empty');
});

test('results with no catalog match flag weak organic coverage', function (): void {
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    $location = diagLocation();
    app()->instance(DataForSeoClient::class, diagDfs([
        ['position' => 1, 'url' => 'https://acme.example', 'domain' => 'acme.example'],
        ['position' => 2, 'url' => 'https://facebook.com/acme', 'domain' => 'facebook.com'],
    ]));

    $report = app(CitationDiagnostics::class)->forLocation($location);

    expect($report->organicRows)->toBe(2)
        ->and($report->directoryHits)->toBe([])
        ->and($report->sampleDomains)->toContain('acme.example')
        ->and($report->severity())->toBe('warning')
        ->and($report->likelyCause())->toContain('none are catalog directories');
});

test('the diagnose command prints the likely cause', function (): void {
    config(['services.dataforseo.login' => null, 'services.dataforseo.password' => null]);
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    $location = diagLocation();

    $this->artisan('launchpad:citation-diagnose', ['--location' => $location->id])
        ->expectsOutputToContain('Likely cause')
        ->assertSuccessful();
});

test('the operator can run scan diagnostics from the board', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    Directory::factory()->create(['domain' => 'yelp.com', 'is_active' => true]);
    diagLocation();
    app()->instance(DataForSeoClient::class, diagDfs([
        ['position' => 1, 'url' => 'https://www.yelp.com/biz/acme', 'domain' => 'www.yelp.com'],
    ]));

    Livewire::test(CitationsBoard::class)
        ->callAction('diagnoseScan')
        ->assertHasNoActionErrors();
});
