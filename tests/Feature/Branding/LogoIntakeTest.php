<?php

use App\Branding\LogoIntake;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('r2'));

/** A two-color PNG (orange + navy) as bytes. */
function twoColorLogo(): string
{
    $img = imagecreatetruecolor(40, 40);
    imagefilledrectangle($img, 0, 0, 39, 23, imagecolorallocate($img, 234, 88, 12));  // orange
    imagefilledrectangle($img, 0, 24, 39, 39, imagecolorallocate($img, 11, 31, 51));   // navy
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('stores the logo to R2 and persists url + extracted colors onto SiteBranding.logo_set', function () {
    $site = Site::factory()->create();

    $set = app(LogoIntake::class)->store($site, twoColorLogo(), 'png');

    expect($set)->toHaveKeys(['url', 'r2_key', 'ext', 'primary', 'accent'])
        ->and($set['ext'])->toBe('png');
    Storage::disk('r2')->assertExists($set['r2_key']);

    $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect($branding->logo_set['url'])->toBe($set['url'])
        ->and($branding->logo_set['primary'])->toBeString()
        ->and($branding->logo_set['accent'])->toBeString();
});

it('stores a monochrome logo with no accent (option will borrow it)', function () {
    $site = Site::factory()->create();
    $img = imagecreatetruecolor(40, 40);
    imagefilledrectangle($img, 4, 4, 35, 35, imagecolorallocate($img, 20, 81, 63)); // single pine block
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    $set = app(LogoIntake::class)->store($site, $bytes, 'png');

    expect($set)->toHaveKey('primary')
        ->and($set)->not->toHaveKey('accent');
});

it('stores a color-less logo with no palette (option must not appear)', function () {
    $site = Site::factory()->create();
    $img = imagecreatetruecolor(40, 40);
    imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, 255, 255, 255)); // all white
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    $set = app(LogoIntake::class)->store($site, $bytes, 'png');

    expect($set)->toHaveKeys(['url', 'r2_key', 'ext'])   // still stored for the header
        ->and($set)->not->toHaveKey('primary');           // but no usable brand color
});
