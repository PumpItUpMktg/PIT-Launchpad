<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A tiny but valid JPEG (§ Job Capture fixtures). The photo store re-encodes every upload to strip its source
 * metadata, so a fixture that must be STORED has to be a real image — a placeholder string is refused.
 */
function tinyJpeg(int $width = 16, int $height = 16): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 120, 120, 120));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/*
|--------------------------------------------------------------------------
| Legacy numbered-twin fixtures (LegacyTwinsTest + LegacyTwinConsolidatorTest)
|--------------------------------------------------------------------------
*/

function twinGscUrl(Site $site, string $path, int $impressions, int $daysAgo = 3, float $position = 9.0, int $clicks = 1): void
{
    GscUrlDaily::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Str::random(32),
        'date' => now()->subDays($daysAgo)->toDateString(), 'url' => 'https://spg.example'.$path,
        'impressions' => $impressions, 'clicks' => $clicks, 'position' => $position,
    ]);
}

/**
 * cost-breakdown: the base + -3 + -8, -3 earns most in the window → keeper; the others redirect to it.
 * install: -2 and -3 tie in the window → ambiguous. valve: -2 earned only last year → earner-lifetime.
 * maintenance-101 is a title. A twin of OUR page is SlugCollisions' business, not a legacy twin.
 */
function twinSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'slug' => 'our-guide']);

    twinGscUrl($site, '/sump-pump-installation-cost-breakdown/', 1_000, daysAgo: 400, position: 14.0);
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-3/', 20_000, daysAgo: 2, position: 6.0, clicks: 150);
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-3', 1_000, daysAgo: 5, position: 6.0); // slash-less form folds in
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-8/', 70_000, daysAgo: 300, position: 30.0);
    twinGscUrl($site, '/sump-pump-installation-cost-breakdown-8/', 5_000, daysAgo: 4, position: 30.0);

    twinGscUrl($site, '/how-to-install-a-sump-pump-correctly-2/', 500, daysAgo: 1);
    twinGscUrl($site, '/how-to-install-a-sump-pump-correctly-3/', 500, daysAgo: 1);

    twinGscUrl($site, '/when-to-replace-sump-pump-check-valve/', 40, daysAgo: 200);
    twinGscUrl($site, '/when-to-replace-sump-pump-check-valve-2/', 59_516, daysAgo: 200);

    twinGscUrl($site, '/sump-pump-maintenance-101/', 7_073);
    twinGscUrl($site, '/our-guide/', 300);
    twinGscUrl($site, '/our-guide-2/', 900);
    twinGscUrl($site, '/blog/page/2/', 10);

    return $site;
}
