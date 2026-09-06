<?php

namespace App\Console\Commands;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Report (§6a ingestion diagnostics): SILO-ALLOCATION WEIGHTING — is candidate ingestion flowing to the
 * silos that are worth writing for? The reactive funnel routes by raw feed/news VOLUME; a silo's business
 * value is its §5 opportunity (revenue-weighted `Keyword.opportunity_score`). When those diverge the queue
 * fills with low-value noise while high-value silos starve.
 *
 * Per silo it compares two SHARES of the site's total:
 *   - candidate share  = the silo's un-triaged candidates (status candidate/in_review) ÷ the site's total.
 *                        The ACTUAL allocation the funnel produced.
 *   - opportunity share = the silo's summed opportunity_score ÷ the site's total. The value-weighted
 *                        allocation ingestion SHOULD roughly track.
 * The delta (candidate − opportunity) flags the mismatch: a large POSITIVE delta = OVER-served (drawing
 * ingestion noise beyond its value); a large NEGATIVE delta = UNDER-served (a high-value silo the funnel
 * is starving). Feed count is shown as context (an over-served silo often just has more feeds pointed at it).
 *
 * READ-ONLY, live-only, all tenants (or one via --site). Advisory — this recommends nothing and changes
 * nothing; it surfaces whether ingestion routing should be weighted by opportunity rather than volume.
 */
class ReportSiloAllocationCommand extends Command
{
    protected $signature = 'launchpad:report-silo-allocation
        {--site= : Limit to one site id or brand name}
        {--flag= : Only list silos whose |delta| share exceeds this (0..1, default 0.10)}';

    protected $description = 'Report (read-only) candidate-ingestion allocation per silo vs its §5 opportunity weight.';

    public function handle(): int
    {
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $flag = (float) ($this->option('flag') ?: 0.10);

        $this->info('Read-only · live-only · candidate share (actual) vs opportunity share (value-weighted target), per silo.');
        $this->newLine();

        foreach ($sites as $site) {
            $this->reportSite($site, $flag);
        }

        return self::SUCCESS;
    }

    private function reportSite(Site $site, float $flag): void
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get(['id', 'name']);
        if ($silos->isEmpty()) {
            return;
        }

        $rows = $silos->map(function (Silo $silo) use ($site): array {
            $candidates = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('silo_id', $silo->id)
                ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::InReview->value])
                ->count();
            $weight = (float) Keyword::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('silo_id', $silo->id)
                ->sum('opportunity_score');
            $feeds = Source::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->where('silo_id', $silo->id)->where('enabled', true)->count();

            return ['name' => (string) $silo->name, 'candidates' => $candidates, 'weight' => $weight, 'feeds' => $feeds];
        })->all();

        $totalCandidates = array_sum(array_column($rows, 'candidates'));
        $totalWeight = array_sum(array_column($rows, 'weight'));

        // Nothing to compare — no candidates AND no scored opportunity on this site yet.
        if ($totalCandidates === 0 && $totalWeight <= 0.0) {
            return;
        }

        foreach ($rows as &$row) {
            $row['cand_share'] = $totalCandidates > 0 ? $row['candidates'] / $totalCandidates : 0.0;
            $row['opp_share'] = $totalWeight > 0.0 ? $row['weight'] / $totalWeight : 0.0;
            $row['delta'] = $row['cand_share'] - $row['opp_share'];
        }
        unset($row);

        usort($rows, fn (array $a, array $b): int => $b['delta'] <=> $a['delta']); // most over-served first

        $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$totalCandidates} un-triaged candidate(s) across ".count($rows).' silo(s):');
        foreach ($rows as $row) {
            $flagged = abs($row['delta']) >= $flag && ($row['candidates'] > 0 || $row['weight'] > 0.0);
            $tag = ! $flagged ? '' : ($row['delta'] > 0 ? '  <comment>⚠ OVER-served</comment>' : '  <error>⚠ UNDER-served (high value, starved)</error>');
            $this->line(sprintf(
                '  %-28s candidates %3d (%5.1f%%) · opportunity %5.1f%% · Δ %+5.1f%% · feeds %d%s',
                Str::limit($row['name'], 27),
                $row['candidates'],
                $row['cand_share'] * 100,
                $row['opp_share'] * 100,
                $row['delta'] * 100,
                $row['feeds'],
                $tag,
            ));
        }
        $this->newLine();
    }

    /** @return Collection<int, Site>|null null on a bad --site */
    private function sites(): ?Collection
    {
        $opt = trim((string) $this->option('site'));
        if ($opt === '') {
            return Site::query()->get();
        }

        $site = Site::withoutGlobalScope(VisibleSiteScope::class)
            ->where('id', $opt)->orWhere('brand_name', $opt)->first();

        if ($site === null) {
            $this->error("No site matches [{$opt}].");

            return null;
        }

        return collect([$site]);
    }
}
