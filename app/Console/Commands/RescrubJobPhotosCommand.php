<?php

namespace App\Console\Commands;

use App\JobCapture\Photos\JobPhotoStore;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Re-scrub already-stored job photos through the current pipeline (§ Job Capture). Photos stored before
 * {@see JobPhotoStore} scrubbed metadata kept whatever their source carried — a phone's or a text message's
 * GPS, XMP location, device tags — minus only the EXIF GPS block, and a photo stored before its job had a
 * point was never stamped at all. Each such photo is decoded, re-encoded metadata-free, stamped with the
 * job's public (jittered) point when it has one, and written back under the SAME key (the live page keeps
 * its URL; a CDN may serve the old bytes until its cache expires).
 *
 * Report-only by default — counts per site, nothing written. --execute rewrites. Idempotent: a photo the
 * current pipeline has scrubbed and stamped is skipped.
 */
class RescrubJobPhotosCommand extends Command
{
    protected $signature = 'launchpad:rescrub-job-photos
        {--site= : Limit to one site id or brand name}
        {--execute : Rewrite the photos (default: report-only — changes nothing)}';

    protected $description = 'Re-scrub stored job photos of source metadata and re-stamp the job\'s public point. Report-only unless --execute.';

    public function handle(JobPhotoStore $photos): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScopes()->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::withoutGlobalScopes()->orderBy('brand_name')->get();
        }

        $execute = (bool) $this->option('execute');
        $this->info($execute
            ? 'EXECUTE · re-scrubbing stored job photos.'
            : 'Read-only · job-photo rescrub PLAN. Nothing is changed (pass --execute to write).');

        $grandPending = 0;
        foreach ($sites as $site) {
            $jobs = Job::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereNotNull('photos')
                ->get(['id', 'site_id', 'status', 'lat_true', 'lng_true', 'lat_jittered', 'lng_jittered', 'photos']);

            $photoRows = 0;
            $pending = 0;
            $noPoint = 0;
            $pendingJobs = [];
            foreach ($jobs as $job) {
                $rows = is_array($job->photos) ? $job->photos : [];
                if ($rows === []) {
                    continue;
                }
                $photoRows += count($rows);
                $unscrubbed = count(array_filter($rows, fn ($r) => ! is_array($r) || ($r['scrubbed'] ?? false) !== true || ($r['geotagged'] ?? false) !== true));
                if ($unscrubbed > 0) {
                    $pending += $unscrubbed;
                    $pendingJobs[] = $job;
                }
                if ($job->lat_true === null && $job->lat_jittered === null) {
                    $noPoint++;
                }
            }
            if ($photoRows === 0) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            $this->line(sprintf(
                '  %d job(s) with photos · %d photo(s) · %d not yet scrubbed+stamped by the current pipeline across %d job(s)%s',
                $jobs->count(),
                $photoRows,
                $pending,
                count($pendingJobs),
                $noPoint > 0 ? " · {$noPoint} job(s) have no point (scrubbed, left ungeotagged)" : '',
            ));
            $grandPending += $pending;

            if ($execute && $pendingJobs !== []) {
                $rewritten = 0;
                foreach ($pendingJobs as $job) {
                    $rewritten += $photos->restamp($job, onlyUnstamped: true);
                }
                $this->info("  Rewrote {$rewritten} photo(s).");
            }
        }

        $this->newLine();
        if ($grandPending === 0) {
            $this->info('Every stored job photo is already scrubbed and stamped by the current pipeline.');

            return self::SUCCESS;
        }
        if (! $execute) {
            $this->info("{$grandPending} photo(s) would be re-scrubbed. Re-run with --execute to write (nothing was changed).");
        }

        return self::SUCCESS;
    }
}
