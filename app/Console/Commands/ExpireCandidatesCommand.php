<?php

namespace App\Console\Commands;

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Expire stale un-triaged candidates (§6a). EVERYTHING un-triaged expires at the window — regardless of
 * shelf-life — because a stale candidate occupies a slot against the silo cap whether it is topical,
 * evergreen, or unclassified, and 30 days is long enough for something nobody wanted to write. Critically,
 * the pre-classification backlog has NO shelf_life at all, so a topical-only filter would never touch the
 * very backlog this is meant to clear. Rejects them (status → rejected, reason=expired) so the review queue
 * stays live, actionable work.
 *
 * Only un-triaged, UNDRAFTED candidates are touched (status candidate/in_review, never a drafted or
 * published row) — an operator's drafted work is never auto-rejected. Aged from the article's publish date
 * (meta.source_published_at) when known, else the ingest/create date (manual + directed candidates have no
 * article, so their created_at is the clock).
 *
 * REPORT-ONLY by default (prints what would expire, broken down per tenant AND per shelf-life so the split
 * is visible before executing); --execute rejects them. Runs daily with --execute (see routes/console.php);
 * the first --execute run also clears the existing backlog. Live-only, all tenants (or one via --site).
 */
class ExpireCandidatesCommand extends Command
{
    protected $signature = 'launchpad:expire-candidates
        {--days= : Age window in days (default: config launchpad.reactive.topical_expiry_days)}
        {--execute : Reject the expired candidates (default is report-only)}
        {--site= : Limit to one site id or brand name}';

    protected $description = 'Reject stale un-triaged candidates older than the expiry window, any shelf-life (report-only by default; --execute applies).';

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
        /** @var array<string, int> $byShelf */
        $byShelf = ['topical' => 0, 'evergreen' => 0, 'unclassified' => 0];

        // EVERY un-triaged candidate expires at the window — not just topical. A stale candidate occupies a
        // slot against the silo cap regardless of shelf-life, and the un-triaged pre-classification backlog
        // has NO shelf_life at all, so a topical-only filter would never touch the very backlog this clears.
        Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::InReview->value])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($cutoff, $execute, $actions, &$expired, &$skippedDrafted, &$bySite, &$byShelf): void {
                foreach ($rows as $candidate) {
                    // Never auto-reject drafted work (a candidate/in_review shouldn't carry a draft, but guard).
                    if ($candidate->hasDraft()) {
                        $skippedDrafted++;

                        continue;
                    }

                    $meta = $candidate->meta ?? [];
                    // Manual + directed candidates have no article date → aged by their ingest/create date.
                    $published = isset($meta['source_published_at']) && $meta['source_published_at'] !== ''
                        ? Carbon::parse((string) $meta['source_published_at'])
                        : $candidate->created_at;

                    if ($published === null || $published->greaterThanOrEqualTo($cutoff)) {
                        continue; // still within the freshness window
                    }

                    $expired++;
                    $bySite[(string) $candidate->site_id] = ($bySite[(string) $candidate->site_id] ?? 0) + 1;
                    $shelf = is_string($meta['shelf_life'] ?? null) && $meta['shelf_life'] !== '' ? $meta['shelf_life'] : 'unclassified';
                    $byShelf[$shelf] = ($byShelf[$shelf] ?? 0) + 1;

                    if ($execute) {
                        $actions->reject($candidate, 'expired'); // same reason for all, so the counters stay comparable
                    }
                }
            });

        $this->info('Live-only · all tenants'.($siteId !== null ? ' (one site)' : '')
            .' · un-triaged candidates older than '.$days.' days (any shelf-life)'
            .($execute ? ' — --execute: rejecting (reason=expired).' : ' — report-only (pass --execute to reject).'));
        $this->newLine();

        if ($expired === 0) {
            $this->info('No stale candidates to expire.'.($skippedDrafted > 0 ? " ({$skippedDrafted} drafted candidate(s) left untouched.)" : ''));

            return self::SUCCESS;
        }

        $verb = $execute ? 'Rejected' : 'Would reject';
        $this->warn("{$verb} {$expired} stale candidate(s) across ".count($bySite).' site(s)'
            .($skippedDrafted > 0 ? " ({$skippedDrafted} drafted left untouched)" : '').'.');

        // Per shelf-life, so the split (esp. how many are the unclassified backlog) is visible before executing.
        $this->line("  by shelf-life: topical {$byShelf['topical']} · evergreen {$byShelf['evergreen']} · unclassified {$byShelf['unclassified']}");
        $this->newLine();
        $this->line('  by tenant:');
        foreach ($bySite as $site => $count) {
            $this->line("    • {$site}: {$count}");
        }

        if (! $execute) {
            $this->newLine();
            $this->comment('Re-run with --execute to reject them (reason=expired).');
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
