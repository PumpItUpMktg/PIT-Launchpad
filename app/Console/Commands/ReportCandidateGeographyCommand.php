<?php

namespace App\Console\Commands;

use App\ContentEngine\Geography\CandidateGeographyReport;
use App\Enums\PlaceVerdict;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * READ-ONLY: the reactive candidate backlog against the tenant's footprint — what the geography drop WOULD
 * remove, before anything is rejected. One narrow model call per un-stamped candidate (newest first, --limit).
 */
class ReportCandidateGeographyCommand extends Command
{
    protected $signature = 'launchpad:report-candidate-geography
        {--site= : Limit to one site id or brand name}
        {--limit=200 : Newest candidates to read per site}
        {--all : List every row, not only the out-of-market and region ones}';

    protected $description = 'Report (read-only) where the current blog candidates are anchored — in footprint, region, out of market, placeless — against the tenant\'s own offices/markets/coverage.';

    public function handle(CandidateGeographyReport $report): int
    {
        $opt = trim((string) $this->option('site'));
        if ($opt !== '') {
            $site = Site::withoutGlobalScope(VisibleSiteScope::class)->where('id', $opt)->orWhere('brand_name', $opt)->first();
            if ($site === null) {
                $this->error("No site matches [{$opt}].");

                return self::FAILURE;
            }
            $sites = collect([$site]);
        } else {
            $sites = Site::query()->get();
        }
        $limit = max(1, (int) $this->option('limit'));
        $this->info("Read-only · reactive candidates (candidate / in_review) against the tenant footprint · newest {$limit} per site.");

        foreach ($sites as $site) {
            $r = $report->report($site, $limit);
            $fp = $r['footprint'];
            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id})");
            if (! $fp->isKnown()) {
                $this->line('  <fg=yellow>Footprint unknown</> — no office / corporate state / market / coverage town carries a state; every candidate reads as "no place".');
            } else {
                $this->line('  Footprint: '.implode(', ', $fp->states).' · region ring: '.($fp->ring !== [] ? implode(', ', $fp->ring) : 'none').' · '.count($fp->towns).' town(s) known');
            }
            $total = array_sum($r['counts']);
            if ($total === 0) {
                $this->line('  No open candidates.');

                continue;
            }
            $this->line(sprintf(
                '  %d candidate(s): <fg=green>%d in footprint</> · %d region · <fg=red>%d out of market</> · %d placeless%s',
                $total,
                $r['counts'][PlaceVerdict::Footprint->value],
                $r['counts'][PlaceVerdict::Region->value],
                $r['counts'][PlaceVerdict::Outside->value],
                $r['counts'][PlaceVerdict::Unknown->value],
                $r['extracted'] > 0 ? " ({$r['extracted']} read by one model call each; the rest were stamped at ingest)" : '',
            ));
            foreach ($r['rows'] as $row) {
                $show = (bool) $this->option('all') || in_array($row['verdict'], [PlaceVerdict::Outside, PlaceVerdict::Region], true);
                if (! $show) {
                    continue;
                }
                $color = match ($row['verdict']) {
                    PlaceVerdict::Outside => 'red',
                    PlaceVerdict::Region => 'yellow',
                    PlaceVerdict::Footprint => 'green',
                    PlaceVerdict::Unknown => 'gray',
                };
                $this->line(sprintf(
                    '    <fg=%s>%-13s</> %s · %s%s · %s · %s%s',
                    $color,
                    $row['verdict']->label(),
                    $row['title'],
                    $row['place'] ?? 'no place',
                    $row['stamped'] ? '' : ' (read now)',
                    $row['source'] ?? 'unknown outlet',
                    $row['status'],
                    $row['created_at'] !== null ? ' · '.$row['created_at'] : '',
                ));
            }
        }

        $this->newLine();
        $this->line('Out-of-market rows are what the geography drop removes at ingest from now on (LAUNCHPAD_GEOGRAPHY_DROP_OUTSIDE). Existing rows are untouched — reject them from the Candidates board if they should go.');

        return self::SUCCESS;
    }
}
