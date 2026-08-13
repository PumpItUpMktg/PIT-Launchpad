<?php

use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\JobCapture\Capture\CsvJobImporter;
use App\Models\Job;
use App\Models\Site;
use Illuminate\Support\Facades\Queue;

/** Geocode every address except one containing BADADDR (which returns null → the row is skipped). */
function bindTolerantGeocoder(): void
{
    app()->instance(Geocoder::class, new class implements Geocoder
    {
        public function geocode(string $address): ?GeocodeResult
        {
            return str_contains($address, 'BADADDR') ? null : new GeocodeResult(40.5, -74.4, $address);
        }
    });
}

it('imports one captured job per valid row', function () {
    Queue::fake();
    bindTolerantGeocoder();
    $site = Site::factory()->create();

    $csv = <<<'CSV'
    client_name,address,performed_at,service_types,description
    Jane Homeowner,"12 Main St, Somerville NJ",2025-06-01,Sump Pump;French Drain,Replaced the pump.
    John Q,"9 Oak Ave, Bedminster NJ",06/15/2025,Sump Pump,Cleared the pit.
    CSV;

    $result = app(CsvJobImporter::class)->import($site, $csv);

    expect($result['imported'])->toBe(2)
        ->and($result['skipped'])->toBe([])
        ->and(Job::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(2);

    $jane = Job::withoutGlobalScopes()->where('client_name_full', 'Jane Homeowner')->first();
    expect($jane->client_name_display)->toBe('Jane H.')
        ->and($jane->performed_at->toDateString())->toBe('2025-06-01')
        ->and($jane->jobTypes()->pluck('label')->all())->toEqualCanonicalizing(['Sump Pump', 'French Drain']);
});

it('skips rows missing a client or address, and rows whose address will not geocode', function () {
    Queue::fake();
    bindTolerantGeocoder();
    $site = Site::factory()->create();

    $csv = <<<'CSV'
    client_name,address,performed_at,service_types,description
    ,"12 Main St",2025-06-01,Sump Pump,No name.
    Valid Client,"5 Real St",,Sump Pump,Good row.
    Ghost Client,"1 BADADDR Ln",,Sump Pump,Ungeocodable.
    CSV;

    $result = app(CsvJobImporter::class)->import($site, $csv);

    expect($result['imported'])->toBe(1)
        ->and($result['skipped'])->toHaveCount(2)
        ->and($result['skipped'][0])->toBe(['row' => 1, 'reason' => 'missing client name or address'])
        ->and($result['skipped'][1])->toBe(['row' => 3, 'reason' => 'address could not be located']);
});

it('tolerates spaced / cased headers', function () {
    Queue::fake();
    bindTolerantGeocoder();
    $site = Site::factory()->create();

    $csv = "Client Name,Address\nJane Homeowner,\"12 Main St\"\n";

    expect(app(CsvJobImporter::class)->import($site, $csv)['imported'])->toBe(1);
});

it('provides a header template with the expected columns', function () {
    expect(app(CsvJobImporter::class)->template())
        ->toContain('client_name,address,performed_at,service_types,description');
});
