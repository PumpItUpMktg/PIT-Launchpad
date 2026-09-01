<?php

use App\JobCapture\Photos\ExifGeotagger;
use App\JobCapture\Photos\JobPhotoStore;
use App\Models\Job;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Storage;

/** A tiny but valid JPEG. */
function sampleJpeg(): string
{
    $img = imagecreatetruecolor(16, 16);
    imagefilledrectangle($img, 0, 0, 15, 15, imagecolorallocate($img, 120, 120, 120));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/** Read GPS back from EXIF bytes via native exif_read_data (writes a temp file). */
function readGps(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);
    $exif = @exif_read_data($path) ?: [];
    @unlink($path);

    return $exif;
}

test('the geotagger writes GPS EXIF that reads back', function (): void {
    $stamped = (new ExifGeotagger)->stamp(sampleJpeg(), 40.1490, -75.3877); // Trooper, PA

    $exif = readGps($stamped);

    expect($exif)->toHaveKey('GPSLatitudeRef')
        ->and($exif['GPSLatitudeRef'])->toBe('N')
        ->and($exif['GPSLongitudeRef'])->toBe('W')
        ->and($exif['GPSLatitude'][0])->toBe('40/1');   // degrees
});

test('the geotagger returns unprocessable bytes unchanged', function (): void {
    $notAnImage = 'this is not an image';

    expect((new ExifGeotagger)->stamp($notAnImage, 40.1, -75.3))->toBe($notAnImage);
});

test('the photo store geotags to the jittered point and persists it once', function (): void {
    Storage::fake('r2');
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create([
        'lat_true' => 40.1490, 'lng_true' => -75.3877,
        'lat_jittered' => null, 'lng_jittered' => null, 'photos' => null,
    ]);

    $rows = app(JobPhotoStore::class)->store($site, $job, [['bytes' => sampleJpeg(), 'filename' => 'x.jpg']], Job::MAX_PHOTOS);

    $job->refresh();
    expect($job->lat_jittered)->not->toBeNull()                       // jitter computed + persisted
        ->and($rows[0]['geotagged'])->toBeTrue()
        ->and(abs($rows[0]['lat'] - (float) $job->lat_jittered))->toBeLessThan(1e-6)  // stamped with the jittered point
        ->and(abs($rows[0]['lng'] - (float) $job->lng_jittered))->toBeLessThan(1e-6);

    // The stored object actually carries the jittered GPS, not the true point.
    $stored = Storage::disk('r2')->get($rows[0]['r2_key']);
    $exif = readGps($stored);
    expect($exif)->toHaveKey('GPSLatitude');
    $deg = (int) explode('/', $exif['GPSLatitude'][0])[0];
    $min = (int) explode('/', $exif['GPSLatitude'][1])[0];
    $stampedLat = $deg + $min / 60 + ((int) explode('/', $exif['GPSLatitude'][2])[0] / 1000) / 3600;
    expect(abs($stampedLat - (float) $job->lat_jittered))->toBeLessThan(0.001)
        ->and(abs($stampedLat - 40.1490))->toBeLessThan(0.02);       // within the ~0.5mi jitter, not the true point exactly
});

test('the photo store leaves photos ungeotagged when there is no point yet', function (): void {
    Storage::fake('r2');
    $site = Site::factory()->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['lat_true' => null, 'lng_true' => null, 'lat_jittered' => null, 'lng_jittered' => null]);

    $rows = app(JobPhotoStore::class)->store($site, $job, [['bytes' => sampleJpeg()]], Job::MAX_PHOTOS);

    expect($rows[0]['geotagged'])->toBeFalse()
        ->and($rows[0])->not->toHaveKey('lat');
});
