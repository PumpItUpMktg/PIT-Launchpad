<?php

use App\JobCapture\Photos\LibraryPhotoAttacher;
use App\JobCapture\Photos\LibraryPhotoUploader;
use App\Models\Account;
use App\Models\Job;
use App\Models\LibraryPhoto;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Storage;

function libJpeg(int $seed = 1): string
{
    $img = imagecreatetruecolor(16, 16);
    imagefilledrectangle($img, 0, 0, 15, 15, imagecolorallocate($img, $seed % 255, 90, 90));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function readGpsLib(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);
    $exif = @exif_read_data($path) ?: [];
    @unlink($path);

    return $exif;
}

test('uploading stores an account library photo with dimensions and dedupes identical bytes', function (): void {
    Storage::fake('r2');
    $account = Account::factory()->create();
    $bytes = libJpeg();

    $first = app(LibraryPhotoUploader::class)->upload($account, $bytes, 'kitchen.jpg');
    $again = app(LibraryPhotoUploader::class)->upload($account, $bytes, 'kitchen-copy.jpg');

    expect($first->account_id)->toBe($account->id)
        ->and($first->width)->toBe(16)
        ->and($first->height)->toBe(16)
        ->and($first->content_type)->toBe('image/jpeg')
        ->and($again->id)->toBe($first->id)                       // deduped by hash
        ->and(LibraryPhoto::query()->where('account_id', $account->id)->count())->toBe(1);
    Storage::disk('r2')->assertExists($first->r2_key);
});

test('attaching a library photo creates a per-job geotagged copy tagged with its source', function (): void {
    Storage::fake('r2');
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['lat_true' => 40.149, 'lng_true' => -75.3877, 'lat_jittered' => null, 'photos' => null]);

    $photo = app(LibraryPhotoUploader::class)->upload($account, libJpeg(), 'kitchen.jpg');

    $added = app(LibraryPhotoAttacher::class)->attach($job, [$photo->id]);

    expect($added)->toBe(1);
    $job->refresh();
    $row = $job->photos[0];
    expect($row['source_library_photo_id'])->toBe((string) $photo->id)
        ->and($row['geotagged'])->toBeTrue()
        ->and($row['r2_key'])->not->toBe($photo->r2_key);         // its OWN per-job copy, not the library original

    // The per-job copy carries the job's jittered GPS.
    $exif = readGpsLib(Storage::disk('r2')->get($row['r2_key']));
    expect($exif)->toHaveKey('GPSLatitude');
});

test('the same library photo attaches to two jobs with each job\'s own location', function (): void {
    Storage::fake('r2');
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    CurrentSite::set($site->id);
    $photo = app(LibraryPhotoUploader::class)->upload($account, libJpeg(), 'x.jpg');

    $a = Job::factory()->for($site)->create(['lat_true' => 40.10, 'lng_true' => -75.30, 'lat_jittered' => null]);
    $b = Job::factory()->for($site)->create(['lat_true' => 41.20, 'lng_true' => -74.10, 'lat_jittered' => null]);

    app(LibraryPhotoAttacher::class)->attach($a, [$photo->id]);
    app(LibraryPhotoAttacher::class)->attach($b, [$photo->id]);

    $a->refresh();
    $b->refresh();
    expect($a->photos[0]['r2_key'])->not->toBe($b->photos[0]['r2_key'])          // distinct copies
        ->and(abs($a->photos[0]['lat'] - $b->photos[0]['lat']))->toBeGreaterThan(0.5); // stamped to different jobs
});

test('attaching respects the per-job photo cap and account isolation', function (): void {
    Storage::fake('r2');
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    CurrentSite::set($site->id);
    $job = Job::factory()->for($site)->create(['lat_true' => 40.1, 'lng_true' => -75.3, 'photos' => [['r2_key' => 'a', 'hash' => 'a'], ['r2_key' => 'b', 'hash' => 'b'], ['r2_key' => 'c', 'hash' => 'c']]]);

    $photo = app(LibraryPhotoUploader::class)->upload($account, libJpeg(), 'x.jpg');
    expect(app(LibraryPhotoAttacher::class)->attach($job, [$photo->id]))->toBe(0); // already at MAX_PHOTOS

    // A photo from another account is not attachable.
    $otherPhoto = LibraryPhoto::factory()->create(); // different account
    $fresh = Job::factory()->for($site)->create(['lat_true' => 40.1, 'lng_true' => -75.3, 'photos' => null]);
    expect(app(LibraryPhotoAttacher::class)->attach($fresh, [$otherPhoto->id]))->toBe(0);
});
