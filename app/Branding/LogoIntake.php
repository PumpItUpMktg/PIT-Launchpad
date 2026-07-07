<?php

namespace App\Branding;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;

/**
 * Takes an uploaded logo: stores it to the tenant's R2 prefix, extracts its brand colors, and persists
 * both onto `SiteBranding.logo_set` — the single record the header (logo URL) and the "Your brand
 * colors" variation (primary/accent) both read.
 *
 * Optional by design: a logo with no usable palette still stores (header logo), it just carries no
 * primary/accent, so the "Your brand colors" option won't appear. No logo at all → nothing stored,
 * everything downstream degrades (text logo, three curated styles).
 */
final class LogoIntake
{
    public function __construct(
        private readonly TenantStorage $storage,
        private readonly LogoColorExtractor $extractor,
        private readonly LogoHeaderTone $headerTone,
    ) {}

    /**
     * @return array{url: string, r2_key: string, ext: string, header_tone: string, primary?: string, accent?: string}
     */
    public function store(Site $site, string $bytes, string $extension): array
    {
        $ext = strtolower(ltrim($extension, '.'));
        $key = $this->storage->put($site, 'brand-logo.'.$ext, $bytes);
        $url = Storage::disk(TenantStorage::DISK)->url($key);

        // Which header background best shows this logo (dark vs light) — chosen, not guessed.
        $set = ['url' => $url, 'r2_key' => $key, 'ext' => $ext, 'header_tone' => $this->headerTone->forLogo($bytes, $ext)];

        $colors = $this->extractor->extract($bytes, $ext);
        if ($colors !== null) {
            $set['primary'] = $colors->primary;
            if ($colors->accent !== null) {
                $set['accent'] = $colors->accent;
            }
        }

        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);
        $existing = is_array($branding->logo_set) ? $branding->logo_set : [];
        // A re-upload replaces the logo + its derived colors/tone cleanly (drop stale values from a prior logo).
        unset($existing['url'], $existing['r2_key'], $existing['ext'], $existing['header_tone'], $existing['primary'], $existing['accent']);
        $branding->update(['logo_set' => array_merge($existing, $set)]);

        return $set;
    }
}
