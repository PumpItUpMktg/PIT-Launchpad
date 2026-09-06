<?php

namespace App\Console\Commands;

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Enums\ShelfLife;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Expire stale TOPICAL candidates (§6a). A topical hook (meta.shelf_life=topical, set at ingestion by the
 * two-axis classification) decays — once its article is older than the window it is no longer worth
 * drafting, and leaving it in the queue just deepens the un-triaged backlog. This sweep rejects such
 * candidates (status → rejected, reason=expired) so the review queue stays live, actionable work.
 *
 * Only un-triaged, UNDRAFTED candidates are touched (status candidate/in_review, never a drafted or
 * published row) — an operator's drafted work is never auto-rejected. EVERGREEN candidates never expire.
 * Aged from the article's publish date (meta.source_published_at) when known, else the ingest date.
 *
 * REPORT-ONLY by default (prints what would expire); --execute rejects them. Runs daily with --execute
 * (see routes/console.php); an operator can run it report-only to preview, and the first --execute run also
 * clears the existing backlog. Live-only, all tenants (or one via --site).
 */
class ExpireCandidatesCommand extends Command
{
    protected $signature = 'launchpad:expire-candidates
        {--days= : Age window in days (default: config launchpad.reactive.topical_expiry_days)}
        {--execute : Reject the expired candidates (default is report-only)}
        {--site= : Limit to one site id or brand name}';

    protected $description = 'Reject stale TOPICAL candidates older than the expiry window (report-only by default; --execute applies).';

    public function handle(ReviewActions $actions): int
    {
        $siteId = $this->resolveSiteId();
        if ($siteId === false) {
            return self::FAILURE;
        }

        $days = (int) ($this->option('days') ?: config('launchpad.reactive.topical_expiry_days', 30));
        $execute = (bool) $this->option('execute');
        $cutoff = Carbon::now()->subDays($days);

        $expired = 0;
        $skippedDrafted = 0;
        /** @var array<string, int> $bySite */
        $bySite = [];

        Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::InReview->value])
            ->where('meta->shelf_life', ShelfLife::Topical->value)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($cutoff, $execute, $actions, &$expired, &$skippedDrafted, &$bySite): void {
                foreach ($rows as $candidate) {
                    // Never auto-reject drafted work (a candidate/in_review shouldn't carry a draft, but guard).
                    if ($candidate->hasDraft()) {
                        $skippedDrafted++;

                        continue;
                    }

                    $meta = $candidate->meta ?? [];
                    $published = isset($meta['source_published_at']) && $meta['source_published_at'] !== ''
                        ? Carbon::parse((string) $meta['source_published_at'])
                        : $candidate->created_at;

                    if ($published === null || $published->greaterThanOrEqualTo($cutoff)) {
                        continue; // still within the freshness window
                    }

                    $expired++;
                    $bySite[(string) $candidate->site_id] = ($bySite[(string) $candidate->site_id] ?? 0) + 1;

                    if ($execute) {
                        $actions->reject($candidate, 'expired');
                    }
                }
            });

        $this->info('Live-only · all tenants'.($siteId !== null ? ' (one site)' : '')
            .' · topical candidates with an article older than '.$days.' days'
            .($execute ? ' — --execute: rejecting (reason=expired).' : ' — report-only (pass --execute to reject).'));
        $this->newLine();

        if ($expired === 0) {
            $this->info('No stale topical candidates to expire.'.($skippedDrafted > 0 ? " ({$skippedDrafted} drafted candidate(s) left untouched.)" : ''));

            return self::SUCCESS;
        }

        $verb = $execute ? 'Rejected' : 'Would reject';
        $this->warn("{$verb} {$expired} stale topical candidate(s) across ".count($bySite).' site(s)'
            .($skippedDrafted > 0 ? " ({$skippedDrafted} drafted left untouched)" : '').'.');
        foreach ($bySite as $site => $count) {
            $this->line("  • {$site}: {$count}");
        }

        if (! $execute) {
            $this->newLine();
            $this->comment('Re-run with --execute to reject them (reason=expired). Candidates minted before the '
                .'shelf_life axis shipped have no meta.shelf_life — run launchpad:classify-candidates first to classify them.');
        }

        return self::SUCCESS;
    }

    /** @return string|null|false site id (null = all tenants, false = resolution error) */
    private function resolveSiteId(): string|null|false
    {
        $opt = trim((string) $this->option('site'));
        if ($opt === '') {
            return null;
        }

        $site = Site::withoutGlobalScope(VisibleSiteScope::class)
            ->where('id', $opt)->orWhere('brand_name', $opt)->first();

        if ($site === null) {
            $this->error("No site matches [{$opt}].");

            return false;
        }

        return (string) $site->id;
    }
}
