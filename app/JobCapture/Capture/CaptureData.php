<?php

namespace App\JobCapture\Capture;

/**
 * A tech's capture submission (§5), normalized off the PWA request. Photos arrive as raw bytes (already
 * downscaled in-browser before entering the offline queue). GPS coordinates are optional: the device
 * supplies them when geolocation is available (even for a walk-in), and their presence is what triggers
 * geography resolution — a walk-in with no coordinates defers its address to operator review (the locked
 * decision). Job types are snapshot pairs (label + slug) with an optional soft ref to the vocabulary row.
 */
final class CaptureData
{
    /**
     * @param  list<array{bytes: string, filename?: string}>  $photos  up to 3, in slot order
     * @param  list<array{label: string, slug: string, job_type_id?: string|null}>  $jobTypes  up to 3
     */
    public function __construct(
        public readonly ?string $clientNameFull = null,
        public readonly ?string $clientNameDisplay = null,
        public readonly ?string $rawDescription = null,
        public readonly ?float $lat = null,
        public readonly ?float $lng = null,
        public readonly array $photos = [],
        public readonly array $jobTypes = [],
        public readonly int $primaryPhotoIndex = 0,
    ) {}
}
