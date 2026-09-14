<?php

use App\Integrations\Census\MunicipalityGazetteer;
use App\JobCapture\Geography\GeographyResolver;
use App\JobCapture\Photos\ExifGeotagger;
use App\JobCapture\Photos\JobPhotoStore;
use App\Models\Job;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Storage;
use lsolesen\pel\PelDataWindow;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelEntryShort;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelTiff;

afterEach(fn () => CurrentSite::clear());

/** A JPEG of the given size (a 2×1 lets an orientation test see the rotation). */
function scrubJpeg(int $w = 16, int $h = 16): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 120, 120, 120));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** Write foreign EXIF into a JPEG: a device Make, an orientation, and (via the old stamp) a GPS block. */
function foreignExif(string $jpeg, int $orientation = 1): string
{
    $pel = new PelJpeg(new PelDataWindow($jpeg));
    $exif = new PelExif;
    $pel->setExif($exif);
    $tiff = new PelTiff;
    $exif->setTiff($tiff);
    $ifd0 = new PelIfd(PelIfd::IFD0);
    $tiff->setIfd($ifd0);
    $ifd0->addEntry(new PelEntryAscii(PelTag::MAKE, 'SomePhoneMaker'));
    $ifd0->addEntry(new PelEntryShort(PelTag::ORIENTATION, $orientation));

    return $pel->getBytes();
}

/** Inject an XMP APP1 segment (the kind editing apps and photo services write) carrying a GPS position. */
function withXmp(string $jpeg): string
{
    $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta xmlns:x=\"adobe:ns:meta/\"><rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">"
        ."<rdf:Description xmlns:exif=\"http://ns.adobe.com/exif/1.0/\" exif:GPSLatitude=\"51,30.0N\" exif:GPSLongitude=\"0,7.0W\"/></rdf:RDF></x:xmpmeta>";
    $segment = "\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

function gpsOf(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);
    $exif = @exif_read_data($path) ?: [];
    @unlink($path);

    return $exif;
}

test('scrub drops every foreign metadata segment (EXIF make, XMP location, old GPS) and writes only our point', function (): void {
    $source = withXmp((new ExifGeotagger)->stamp(foreignExif(scrubJpeg()), 51.5, -0.12)); // London GPS + XMP + Make
    expect($source)->toContain('ns.adobe.com/xap')->toContain('SomePhoneMaker');

    $clean = (new ExifGeotagger)->scrub($source, 40.1490, -75.3877); // Trooper, PA

    expect($clean)->not->toBeNull()
        ->and($clean)->not->toContain('ns.adobe.com/xap')
        ->and($clean)->not->toContain('SomePhoneMaker');
    $exif = gpsOf($clean);
    expect($exif['GPSLatitudeRef'])->toBe('N')
        ->and($exif['GPSLatitude'][0])->toBe('40/1')
        ->and($exif['GPSLongitudeRef'])->toBe('W')
        ->and($exif['GPSLongitude'][0])->toBe('75/1')
        ->and($exif)->not->toHaveKey('Make');
});

test('scrub with no point writes a clean image with no GPS at all', function (): void {
    $clean = (new ExifGeotagger)->scrub(withXmp((new ExifGeotagger)->stamp(scrubJpeg(), 51.5, -0.12)), null, null);

    expect($clean)->not->toBeNull()->and($clean)->not->toContain('ns.adobe.com/xap');
    expect(gpsOf($clean))->not->toHaveKey('GPSLatitude');
});

test('scrub applies the source orientation so the pixels are upright without the tag', function (): void {
    $clean = (new ExifGeotagger)->scrub(foreignExif(scrubJpeg(2, 1), 6), null, null); // rotate 90° CW

    [$w, $h] = getimagesizefromstring((string) $clean);
    expect([$w, $h])->toBe([1, 2])
        ->and(gpsOf((string) $clean))->not->toHaveKey('Orientation');
});

test('an undecodable upload is refused by the store — never stored with its source metadata', function (): void {
    Storage::fake('r2');
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['photos' => null]);

    $rows = app(JobPhotoStore::class)->store($site, $job, [
        ['bytes' => 'definitely not an image', 'filename' => 'bad.jpg'],
        ['bytes' => scrubJpeg(), 'filename' => 'good.jpg'],
    ], Job::MAX_PHOTOS);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['scrubbed'])->toBeTrue()
        ->and($rows[0]['geotagged'])->toBeTrue();
    expect((new ExifGeotagger)->scrub('definitely not an image', 1.0, 1.0))->toBeNull();
    expect(count(Storage::disk('r2')->allFiles()))->toBe(1);
});

test('restamp rewrites legacy photos in place with the current public point and flags the rows', function (): void {
    Storage::fake('r2');
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['lat_jittered' => 40.1500, 'lng_jittered' => -75.3900]);

    // A legacy row: stored by the old pipeline with a phone's London GPS + XMP, no `scrubbed` flag.
    $key = 'sites/'.$site->id.'/jobs/'.$job->id.'/1.jpg';
    Storage::disk('r2')->put($key, withXmp((new ExifGeotagger)->stamp(scrubJpeg(), 51.5, -0.12)));
    $job->forceFill(['photos' => [['r2_key' => $key, 'hash' => 'old', 'geotagged' => true]]])->save();

    $rewritten = app(JobPhotoStore::class)->restamp($job, onlyUnstamped: true);

    $stored = Storage::disk('r2')->get($key);
    expect($rewritten)->toBe(1)
        ->and($stored)->not->toContain('ns.adobe.com/xap')
        ->and(gpsOf($stored)['GPSLongitude'][0])->toBe('75/1')      // our point, not London
        ->and($job->fresh()->photos[0]['scrubbed'])->toBeTrue()
        ->and($job->fresh()->photos[0]['hash'])->not->toBe('old')
        ->and(abs($job->fresh()->photos[0]['lat'] - 40.15))->toBeLessThan(1e-6);

    // Idempotent: nothing left to do.
    expect(app(JobPhotoStore::class)->restamp($job, onlyUnstamped: true))->toBe(0);
});

test('the geography resolver stamps photos that were stored before the job had a point', function (): void {
    Storage::fake('r2');
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('placeAt')->andReturn(null);
    $gazetteer->shouldReceive('countyAt')->andReturn(null);
    app()->instance(MunicipalityGazetteer::class, $gazetteer);

    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['lat_true' => null, 'lng_true' => null, 'lat_jittered' => null, 'lng_jittered' => null, 'photos' => null]);

    // Stored with no point: scrubbed, ungeotagged.
    $rows = app(JobPhotoStore::class)->store($site, $job, [['bytes' => scrubJpeg(), 'filename' => 'p.jpg']], Job::MAX_PHOTOS);
    $job->forceFill(['photos' => $rows])->save();
    expect($rows[0]['geotagged'])->toBeFalse();

    // The operator places the job; geography resolves; the photo gets the public point.
    $job->forceFill(['lat_true' => 40.1490, 'lng_true' => -75.3877])->save();
    app(GeographyResolver::class)->resolve($job->fresh());

    $job = $job->fresh();
    expect($job->lat_jittered)->not->toBeNull()
        ->and($job->photos[0]['geotagged'])->toBeTrue()
        ->and(gpsOf(Storage::disk('r2')->get($job->photos[0]['r2_key'])))->toHaveKey('GPSLatitude');
});

test('the rescrub command reports legacy photos and rewrites them only on --execute', function (): void {
    Storage::fake('r2');
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create();
    $key = 'sites/'.$site->id.'/jobs/'.$job->id.'/1.jpg';
    Storage::disk('r2')->put($key, withXmp(scrubJpeg()));
    $job->forceFill(['photos' => [['r2_key' => $key, 'hash' => 'old', 'geotagged' => false]]])->save();
    CurrentSite::clear();

    $this->artisan('launchpad:rescrub-job-photos', ['--site' => 'SPG'])
        ->assertSuccessful()
        ->expectsOutputToContain('Read-only');
    expect(Storage::disk('r2')->get($key))->toContain('ns.adobe.com/xap'); // untouched

    $this->artisan('launchpad:rescrub-job-photos', ['--site' => 'SPG', '--execute' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Rewrote 1 photo(s)');
    expect(Storage::disk('r2')->get($key))->not->toContain('ns.adobe.com/xap')
        ->and($job->fresh()->photos[0]['scrubbed'])->toBeTrue()
        ->and($job->fresh()->photos[0]['geotagged'])->toBeTrue();

    $this->artisan('launchpad:rescrub-job-photos', ['--site' => 'SPG'])
        ->assertSuccessful()
        ->expectsOutputToContain('already scrubbed');
});
