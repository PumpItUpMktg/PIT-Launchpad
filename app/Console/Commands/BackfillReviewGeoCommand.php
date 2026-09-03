<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeReview;
use App\Models\Review;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;

/**
 * Backfill the geocoded point (and, for first-party rows, the town/state) on reviews that predate the
 * town/geo columns, so the location-page reviews section can radius-filter them. A review is RESOLVABLE when
 * it carries some address to geocode — its own town, or a raw service_address; one with neither cannot be
 * placed and needs operator attention. `--dry-run` reports the resolvable/unresolvable split and changes
 * nothing; the real run dispatches one idempotent {@see GeocodeReview} per resolvable review (which skips a
 * review that already has a point, so re-running is safe). `--site=` limits to one tenant.
 */
class BackfillReviewGeoCommand extends Command
{
    protected $signature = 'launchpad:backfill-review-geo {--site= : limit to one site id} {--dry-run : report resolvable/unresolvable counts, change nothing}';

    protected $description = 'Queue geocoding for reviews missing a point. --dry-run reports how many can/cannot be resolved.';

    public function handle(): int
    {
        $siteId = $this->option('site');
        $dryRun = (bool) $this->option('dry-run');

        $reviews = Review::withoutGlobalScope(SiteScope::class)
            ->whereNull('lat')
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->get(['id', 'site_id', 'town', 'service_address']);

        $resolvable = $reviews->filter(
            fn (Review $r): bool => trim((string) $r->town) !== '' || trim((string) $r->service_address) !== ''
        );
        $unresolvable = $reviews->count() - $resolvable->count();

        $this->info(sprintf(
            '%d review(s) missing a point: %d resolvable (have a town or address), %d unresolvable (no location data).',
            $reviews->count(),
            $resolvable->count(),
            $unresolvable,
        ));

        if ($dryRun) {
            $this->comment('Dry run — nothing queued. Re-run without --dry-run to geocode the resolvable reviews.');

            return self::SUCCESS;
        }

        foreach ($resolvable as $review) {
            GeocodeReview::dispatch((string) $review->id);
        }
        $this->info(sprintf('Queued %d GeocodeReview job(s) (idempotent).', $resolvable->count()));

        return self::SUCCESS;
    }
}
