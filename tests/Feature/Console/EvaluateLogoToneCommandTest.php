<?php

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;

/** A small PNG with dark ink on transparency — reads as "dark logo → light header". */
function darkLogoBytes(): string
{
    $img = imagecreatetruecolor(40, 20);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    imagefilledrectangle($img, 0, 0, 11, 19, imagecolorallocate($img, 20, 22, 26));
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

function toneOf(Site $site): ?string
{
    $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

    return $branding?->logo_set['header_tone'] ?? null;
}

it('backfills header_tone for an existing logo read from R2 (dark logo → light header)', function () {
    Storage::fake(TenantStorage::DISK);
    $site = Site::factory()->create();
    Storage::disk(TenantStorage::DISK)->put('sites/x/brand-logo.png', darkLogoBytes());
    // A logo uploaded before the feature: no header_tone on the set.
    SiteBranding::factory()->create([
        'site_id' => $site->id,
        'logo_set' => ['url' => 'https://cdn.example/x.png', 'r2_key' => 'sites/x/brand-logo.png', 'ext' => 'png'],
    ]);

    $this->artisan('launchpad:evaluate-logo-tone', ['site' => $site->id])->assertSuccessful();

    expect(toneOf($site))->toBe('light'); // the dark logo now flips the header light
});

it('leaves an already-evaluated tone alone unless --force', function () {
    Storage::fake(TenantStorage::DISK);
    $site = Site::factory()->create();
    Storage::disk(TenantStorage::DISK)->put('sites/x/brand-logo.png', darkLogoBytes());
    SiteBranding::factory()->create([
        'site_id' => $site->id,
        'logo_set' => ['r2_key' => 'sites/x/brand-logo.png', 'ext' => 'png', 'header_tone' => 'dark'],
    ]);

    $this->artisan('launchpad:evaluate-logo-tone', ['site' => $site->id])->assertSuccessful();
    expect(toneOf($site))->toBe('dark'); // untouched

    $this->artisan('launchpad:evaluate-logo-tone', ['site' => $site->id, '--force' => true])->assertSuccessful();
    expect(toneOf($site))->toBe('light'); // recomputed
});
