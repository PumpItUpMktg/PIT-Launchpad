<?php

namespace App\Console\Commands;

use App\ContentEngine\Coverage\CandidateCoverage;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\CoverageVerdict;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * The coverage verdict on the current candidate backlog — report-first; --execute stamps `meta.coverage` on each
 * row so the board shows it. Candidates ingested after this shipped are stamped at ingest; this backfills the rest
 * (or re-checks every row with --all, e.g. after a new post publishes).
 */
class AssessCoverageCommand extends Command
{
    protected $signature = 'launchpad:assess-coverage
        {--site= : Limit to one site id or brand name}
        {--all : Re-check every open candidate, not only the un-stamped ones}
        {--execute : Write the verdict on each candidate (meta.coverage); report-only by default}';

    protected $description = 'Check each open blog candidate for a live post that already covers its topic (and that post\'s Search Console standing): author / refresh / covered. Report-first; --execute stamps the board.';

    public function handle(CandidateCoverage $coverage): int
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
        $execute = (bool) $this->option('execute');
        $this->info(($execute ? 'Stamping' : 'Read-only ·').' coverage verdicts on open blog candidates (candidate / scored / in_review) against the live posts.');

        $totals = array_fill_keys(array_map(fn (CoverageVerdict $v): string => $v->value, CoverageVerdict::cases()), 0);
        foreach ($sites as $site) {
            $rows = Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('kind', ContentKind::Post->value)
                ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value, ContentStatus::InReview->value])
                ->when(! $this->option('all'), fn ($q) => $q->whereNull('meta->coverage'))
                ->orderByDesc('created_at')
                ->get();
            if ($rows->isEmpty()) {
                continue;
            }
            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) · {$rows->count()} candidate(s)");
            foreach ($rows as $row) {
                $decision = $execute ? $coverage->stamp($site, $row) : $coverage->decide($site, $row);
                $totals[$decision->verdict->value]++;
                if ($decision->verdict === CoverageVerdict::Author) {
                    continue;
                }
                $standing = $decision->position !== null
                    ? sprintf('pos %.1f · %d impr', $decision->position, $decision->impressions)
                    : 'no GSC standing';
                $this->line(sprintf(
                    '    <fg=%s>%-17s</> %s  →  “%s” (%s · sim %.2f)',
                    $decision->verdict === CoverageVerdict::Covered ? 'red' : 'yellow',
                    $decision->verdict->label(),
                    $row->title,
                    $decision->twinTitle,
                    $standing,
                    $decision->similarity,
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '<info>%d</info> new post · <fg=yellow>%d</> refresh existing · <fg=red>%d</> covered%s',
            $totals[CoverageVerdict::Author->value],
            $totals[CoverageVerdict::Refresh->value],
            $totals[CoverageVerdict::Covered->value],
            $execute ? ' — stamped on the rows; the Candidates board now shows the verdicts.' : ' — nothing written; pass --execute to stamp the rows.',
        ));

        return self::SUCCESS;
    }
}
