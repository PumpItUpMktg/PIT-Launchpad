<?php

namespace App\Publishing;

use App\Models\Account;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes rendered images to R2 under a per-tenant prefix (blast-radius
 * containment from §9: one tenant's media can never collide with another's).
 * Returns the stored object key; images serve from the R2/CDN URL directly,
 * never the WP media library.
 */
class TenantStorage
{
    public const DISK = 'r2';

    public function prefixFor(Site $site): string
    {
        return 'sites/'.$site->id;
    }

    /**
     * Store image bytes for a site and return the R2 object key.
     */
    public function put(Site $site, string $filename, string $bytes): string
    {
        $key = $this->prefixFor($site).'/'.$this->sanitize($filename);

        Storage::disk(self::DISK)->put($key, $bytes);

        return $key;
    }

    /**
     * Store a Job Capture photo under the per-tenant, per-job prefix
     * (`sites/{site}/jobs/{job}/{file}`) so a job's images are grouped and never collide across jobs.
     */
    public function putForJob(Site $site, string $jobId, string $filename, string $bytes): string
    {
        $key = $this->prefixFor($site).'/jobs/'.$jobId.'/'.$this->sanitize($filename);

        Storage::disk(self::DISK)->put($key, $bytes);

        return $key;
    }

    /**
     * Store a reusable library original under the per-account library prefix
     * (`accounts/{account}/library/{file}`). The source images the operator uploads once and attaches to many
     * jobs; each attachment gets its own geotagged copy under a job prefix, never this original.
     */
    public function putForLibrary(Account $account, string $filename, string $bytes): string
    {
        $key = 'accounts/'.$account->id.'/library/'.$this->sanitize($filename);

        Storage::disk(self::DISK)->put($key, $bytes);

        return $key;
    }

    private function sanitize(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'image';

        return $ext !== '' ? "{$base}.{$ext}" : $base;
    }
}
