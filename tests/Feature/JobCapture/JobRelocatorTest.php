<?php

use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\Integrations\Census\MunicipalityGazetteer;
use App\JobCapture\Capture\CouldNotPlaceJobException;
use App\JobCapture\Geography\GeographyResolver;
use App\JobCapture\Photos\JobPhotoStore;
use App\JobCapture\Review\JobRelocator;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;
use App\Models\JobCity;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

afterEach(fn () => CurrentSite::clear());

/** A geocoder that resolves any non-empty address to one fixed point (the default test binding returns null). */
function relocGeocoderAt(?float $lat, ?float $lng): void
{
    app()->instance(Geocoder::class, new class($lat, $lng) implements Geocoder
    {
        public function __construct(private ?float $lat, private ?float $lng) {}

        public function geocode(string $address): ?GeocodeResult
        {
            return $this->lat === null || $this->lng === null ? null : new GeocodeResult($this->lat, $this->lng, 'MATCHED: '.$address);
        }
    });
}

/** Decimal latitude read back from a stored JPEG's EXIF GPS. */
function relocStampedLat(string $bytes): ?float
{
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);
    $exif = @exif_read_data($path) ?: [];
    @unlink($path);
    if (! isset($exif['GPSLatitude'])) {
        return null;
    }
    [$d, $m, $s] = array_map(fn (string $r): float => (float) explode('/', $r)[0] / (float) explode('/', $r)[1], $exif['GPSLatitude']);

    return ($d + $m / 60 + $s / 3600) * ($exif['GPSLatitudeRef'] === 'S' ? -1 : 1);
}

it('moves the job to the geocoded address and resets everything derived from the old point', function () {
    Queue::fake();
    relocGeocoderAt(40.5600, -74.6100); // Somerville, NJ
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $city = JobCity::factory()->create(['name' => 'Office Town', 'state' => 'PA']);
    $job = Job::factory()->for($site)->create([
        'address_true' => '1 Office Park, Trooper PA', 'lat_true' => 40.1490, 'lng_true' => -75.3877,
        'lat_jittered' => 40.1500, 'lng_jittered' => -75.3900, 'job_city_id' => $city->id,
        'photos' => [['r2_key' => 'a.jpg', 'hash' => 'h', 'scrubbed' => true, 'geotagged' => true, 'lat' => 40.15, 'lng' => -75.39]],
    ]);

    $point = app(JobRelocator::class)->relocate($job, '12 Main St, Somerville NJ');

    $job->refresh();
    expect($point->matchedAddress)->toBe('MATCHED: 12 Main St, Somerville NJ')
        ->and($job->address_true)->toBe('12 Main St, Somerville NJ')
        ->and((float) $job->lat_true)->toBe(40.56)
        ->and((float) $job->lng_true)->toBe(-74.61)
        ->and($job->lat_jittered)->toBeNull()          // the jitter is recomputed from the NEW point
        ->and($job->job_city_id)->toBeNull()           // town/county re-resolved
        ->and($job->photos[0]['geotagged'])->toBeFalse() // stale photo GPS flagged for re-stamp
        ->and($job->photos[0])->not->toHaveKey('lat')
        ->and($job->photos[0]['scrubbed'])->toBeTrue(); // scrub state is unaffected
    Queue::assertPushed(ResolveJobGeography::class, fn (ResolveJobGeography $j): bool => $j->jobId === $job->id);
});

it('re-jitters and re-stamps every photo with the new public point once geography resolves', function () {
    Storage::fake('r2');
    Queue::fake();
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('placeAt')->andReturn(null);
    $gazetteer->shouldReceive('countyAt')->andReturn(null);
    app()->instance(MunicipalityGazetteer::class, $gazetteer);
    app()->instance(CensusPopulation::class, Mockery::mock(CensusPopulation::class));

    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['lat_true' => 40.1490, 'lng_true' => -75.3877, 'lat_jittered' => null, 'lng_jittered' => null, 'photos' => null]);
    $job->forceFill(['photos' => app(JobPhotoStore::class)->store($site, $job, [['bytes' => tinyJpeg()]], Job::MAX_PHOTOS)])->save();
    $job->refresh();
    $oldJitter = (float) $job->lat_jittered;
    $key = $job->photos[0]['r2_key'];
    expect(abs((float) relocStampedLat(Storage::disk('r2')->get($key)) - 40.1490))->toBeLessThan(0.02); // stamped at the office

    relocGeocoderAt(40.5600, -74.6100);
    app(JobRelocator::class)->relocate($job->fresh(), '12 Main St, Somerville NJ');
    app(GeographyResolver::class)->resolve($job->fresh()); // what the queued ResolveJobGeography runs

    $job->refresh();
    expect($job->lat_jittered)->not->toBeNull()
        ->and(abs((float) $job->lat_jittered - 40.56))->toBeLessThan(0.02)    // jittered around the NEW point
        ->and(abs((float) $job->lat_jittered - $oldJitter))->toBeGreaterThan(0.1)
        ->and($job->photos[0]['geotagged'])->toBeTrue()
        ->and(abs((float) $job->photos[0]['lat'] - (float) $job->lat_jittered))->toBeLessThan(1e-6)
        ->and(abs((float) relocStampedLat(Storage::disk('r2')->get($key)) - (float) $job->lat_jittered))->toBeLessThan(0.001); // same key, new GPS
});

it('refuses an address it cannot geocode and changes nothing', function () {
    Queue::fake();
    relocGeocoderAt(null, null);
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['address_true' => '1 Office Park', 'lat_jittered' => 40.15, 'lng_jittered' => -75.39]);

    expect(fn () => app(JobRelocator::class)->relocate($job, 'nowhere at all'))->toThrow(CouldNotPlaceJobException::class);

    $job->refresh();
    expect($job->address_true)->toBe('1 Office Park')->and((float) $job->lat_jittered)->toBe(40.15);
    Queue::assertNothingPushed();
});
