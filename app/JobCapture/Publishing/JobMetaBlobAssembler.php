<?php

namespace App\JobCapture\Publishing;

use App\Models\Job;
use App\Publishing\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the consolidated payload for the companion plugin's `/job` endpoint (§9), keyed on the ULID for an
 * idempotent upsert. Privacy is enforced at the boundary: ONLY public-safe fields cross the wire — the
 * display name (First + Last initial), the resolved city/county/state, and the JITTERED coordinates. The
 * true address and exact point never leave the control plane. Images are referenced by their R2 key + CDN
 * URL (served from R2/CDN, never the WP media library).
 */
final class JobMetaBlobAssembler
{
    /** @return array<string, mixed> */
    public function assemble(Job $job): array
    {
        $title = trim((string) $job->post_title) ?: $this->fallbackTitle($job);

        return [
            'job_id' => $job->id,
            'status' => 'publish',
            'title' => $title,
            'slug' => Str::slug($title.'-'.substr($job->id, -6)),
            'description' => (string) $job->enhanced_description,
            'client_name' => (string) $job->client_name_display,
            'seo' => [
                'title' => $title,
                'meta_description' => (string) $job->meta_description,
            ],
            'location' => [
                'city' => $job->job_city_id !== null ? $job->city->name : null,
                'county' => $job->job_county_id !== null ? $job->county->name : null,
                'state' => $job->job_city_id !== null ? $job->city->state : null,
                // JITTERED coordinates only — the true point is never published.
                'lat' => $job->lat_jittered !== null ? (float) $job->lat_jittered : null,
                'lng' => $job->lng_jittered !== null ? (float) $job->lng_jittered : null,
            ],
            'job_types' => $job->jobTypes->map(fn ($t): array => ['label' => $t->label, 'slug' => $t->slug])->all(),
            'images' => $this->images($job),
        ];
    }

    /** @return list<array{key: string, url: string, alt: string, primary: bool}> */
    private function images(Job $job): array
    {
        $photos = is_array($job->photos) ? $job->photos : [];
        $primary = $job->primary_photo_index;

        $out = [];
        foreach ($photos as $i => $photo) {
            $key = trim($photo['r2_key']);
            if ($key === '') {
                continue;
            }
            $out[] = [
                'key' => $key,
                'url' => $this->url($key),
                'alt' => (string) ($photo['alt'] ?? ''),
                'primary' => $i === $primary,
            ];
        }

        return $out;
    }

    private function url(string $key): string
    {
        try {
            return Storage::disk(TenantStorage::DISK)->url($key);
        } catch (Throwable) {
            return $key;   // no public URL configured (e.g. tests) — the plugin resolves from the key
        }
    }

    private function fallbackTitle(Job $job): string
    {
        $type = $job->jobTypes->first()?->label;
        $city = $job->job_city_id !== null ? $job->city->name : null;

        return trim(($type ?: 'Completed Job').($city !== null && $city !== '' ? " in {$city}" : ''));
    }
}
