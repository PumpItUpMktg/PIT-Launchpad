<?php

namespace App\JobCapture\Review;

use App\Integrations\Census\GeocodeResult;
use App\Integrations\Census\Geocoder;
use App\JobCapture\Capture\CouldNotPlaceJobException;
use App\JobCapture\Geography\GeographyResolver;
use App\Jobs\ResolveJobGeography;
use App\Models\Job;

/**
 * Re-place a job at review (§8): the operator types the job's real street address and the job moves there.
 * A job captured from the office (the tech uploaded past-job photos where they sat, not where they worked)
 * or backfilled against a mistyped address carries the WRONG point — and everything downstream of the point
 * is wrong with it: the public jittered pin, the town/county page it files under, and the GPS stamped into
 * every photo.
 *
 * Relocating geocodes the address to the new TRUE point and resets what derives from the old one: the
 * stored jitter (normally computed once and kept stable — a deliberate new placement is the one time it is
 * recomputed), the resolved city/county links, and each photo's stamped location (flagged un-geotagged so
 * the resolver re-stamps them with the new public point). {@see GeographyResolver} then runs off the request
 * exactly as it does for a fresh capture. The write-up is NOT touched: if it names the old town the operator
 * re-enhances; a job that was already live republishes on re-approve (same wp_post_id).
 */
final class JobRelocator
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /**
     * @return GeocodeResult the matched address + point, so the operator can confirm where it landed
     *
     * @throws CouldNotPlaceJobException when the address can't be geocoded to a point
     */
    public function relocate(Job $job, string $address): GeocodeResult
    {
        $address = trim($address);
        $point = $address !== '' ? $this->geocoder->geocode($address) : null;
        if ($point === null) {
            throw new CouldNotPlaceJobException('Couldn’t find that address — check it and try again.');
        }

        // Every photo's stamped point is now stale: drop it and flag the row so the resolver re-stamps it.
        $rows = is_array($job->photos) ? $job->photos : [];
        foreach ($rows as $i => $row) {
            unset($row['lat'], $row['lng']);
            $row['geotagged'] = false;
            $rows[$i] = $row;
        }

        $job->forceFill([
            'address_true' => $address,
            'lat_true' => $point->lat,
            'lng_true' => $point->lng,
            'lat_jittered' => null,   // recomputed by the resolver from the new true point
            'lng_jittered' => null,
            'job_city_id' => null,    // re-resolved
            'job_county_id' => null,
            'photos' => $rows !== [] ? $rows : $job->photos,
        ])->save();

        ResolveJobGeography::dispatch($job->id);

        return $point;
    }
}
