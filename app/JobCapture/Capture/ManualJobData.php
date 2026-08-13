<?php

namespace App\JobCapture\Capture;

/**
 * An operator's manual/backfill job entry (§5) — the same shape as a tech's {@see CaptureData}, minus GPS
 * (there's no device fix) and plus the two things only an operator supplies: the typed street `address`
 * (geocoded to a point by {@see ManualJobIntake}) and the real `performedAt` date of a previous job.
 */
final class ManualJobData
{
    /**
     * @param  list<array{bytes: string, filename?: string}>  $photos  up to 3
     * @param  list<array{label: string, slug?: string, job_type_id?: string|null}>  $jobTypes  up to 3
     */
    public function __construct(
        public readonly string $clientName,
        public readonly string $address,
        public readonly ?string $performedAt = null,
        public readonly ?string $rawDescription = null,
        public readonly array $jobTypes = [],
        public readonly array $photos = [],
    ) {}
}
