<?php

namespace App\Console\Commands;

use App\Enums\ArrangeFlagType;
use App\Enums\SpokeGranularity;
use App\Interview\Arrange\ArrangeResult;
use App\Interview\Arrange\AutoArranger;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * auto-arrange — run the structural passes (B→C→A→D→E) for a site and show the resulting tree.
 *
 * Default writes the recommended structure (in a committed transaction). With **--dry-run**
 * the same run executes in a rolled-back transaction (writes nothing) — the output-check
 * surface for tuning the four cosine thresholds against live numbers. Either way it prints
 * the tree — pillars, sub-hubs, own-page cores, nested folded spokes, each page with its
 * primary keyword — plus a summary of the dedup merges, the demotion recommendations, and
 * every flag with its score.
 *
 * Auto-arrange only sets defaults on undecided/auto nodes; operator-confirmed structure is
 * preserved and an auto default re-flips only past the margin, so re-running is safe.
 *
 *   launchpad:auto-arrange {site} [--dry-run]
 */
class AutoArrangeCommand extends Command
{
    protected $signature = 'launchpad:auto-arrange
        {site : the Site id}
        {--dry-run : preview the recommended structure without writing}';

    protected $description = 'Run auto-arrange (B→C→A→D→E) for a site; --dry-run previews without writing.';

    public function handle(AutoArranger $arranger): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Both paths run in a transaction — committed on write, rolled back on dry-run so the
        // preview is read-only by construction.
        DB::beginTransaction();
        try {
            $result = $arranger->arrange($site);
            $spokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
            $this->printTree($spokes);
            $this->printSummary($result);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($dryRun) {
            DB::rollBack();
            $this->newLine();
            $this->comment('Dry run — nothing was written.');
        } else {
            DB::commit();
            $this->newLine();
            $this->info('Applied — structure written.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Spoke>  $spokes
     */
    private function printTree(Collection $spokes): void
    {
        $pillars = $spokes->filter(fn (Spoke $s) => $s->is_pillar);
        $roots = $pillars->reject(fn (Spoke $s) => $s->isSubHub())->sortBy('silo')->values();

        $this->line('<options=bold>Proposed structure</>');

        foreach ($roots as $pillar) {
            $this->line('● '.$pillar->silo.'  '.$this->kw($pillar));
            $this->printSiloBody($pillar, $spokes, 1);

            // Sub-hubs parented under this root.
            $subHubs = $pillars
                ->filter(fn (Spoke $s) => $s->isSubHub() && $s->parent_silo_id === $pillar->id)
                ->sortBy('silo');
            foreach ($subHubs as $sub) {
                $this->line(str_repeat('  ', 1).'◐ '.$sub->silo.' (sub-hub)  '.$this->kw($sub));
                $this->printSiloBody($sub, $spokes, 2);
            }
        }
    }

    /**
     * Own-page cores of a silo (with their folded sections), then sections that fold onto the pillar.
     *
     * @param  Collection<int, Spoke>  $spokes
     */
    private function printSiloBody(Spoke $pillar, Collection $spokes, int $depth): void
    {
        $pad = str_repeat('  ', $depth);
        $inSilo = $spokes->where('silo', $pillar->silo)->reject(fn (Spoke $s) => $s->is_pillar);

        $cores = $inSilo
            ->filter(fn (Spoke $s) => $s->granularity === SpokeGranularity::OwnPage)
            ->sortByDesc('volume');

        foreach ($cores as $core) {
            $this->line($pad.'├─ '.$core->name.'  '.$this->kw($core).$this->vol($core));
            foreach ($this->foldedInto($inSilo, $core->id) as $section) {
                $this->line($pad.'│   ↳ '.$section->name.$this->nest($section));
            }
        }

        // Sections folded directly onto the pillar (no own-page core parent).
        foreach ($this->foldedInto($inSilo, $pillar->id) as $section) {
            $this->line($pad.'↳ '.$section->name.' (under pillar)'.$this->nest($section));
        }
    }

    /**
     * @param  Collection<int, Spoke>  $inSilo
     * @return Collection<int, Spoke>
     */
    private function foldedInto(Collection $inSilo, string $targetId): Collection
    {
        return $inSilo
            ->filter(fn (Spoke $s) => $s->granularity === SpokeGranularity::Folded && $s->fold_into_id === $targetId)
            ->sortByDesc('volume');
    }

    private function printSummary(ArrangeResult $result): void
    {
        $applied = $result->applied;

        $this->newLine();
        $this->line('<options=bold>Summary</>');
        $this->line(sprintf(
            '  dedup merges: %d   keyword folds: %d   demotion recs: %d   flags: %d',
            $applied['dedup'] ?? 0,
            $applied['keyword_fold'] ?? 0,
            count($result->flagsOfType(ArrangeFlagType::SubHubDemotion)),
            count($result->flags),
        ));

        if ($result->flags === []) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>Flags (operator confirm)</>');
        foreach ($result->flags as $flag) {
            $score = $flag->candidates[0]['score'] ?? null;
            $this->line(sprintf(
                '  • [%s] %s%s',
                $flag->type->label(),
                $flag->message,
                $score !== null ? "  (score {$score})" : '',
            ));
        }
    }

    private function kw(Spoke $page): string
    {
        $kw = (string) ($page->primary_keyword ?? '');
        $line = $kw === '' ? '〔—〕' : "〔{$kw}〕";
        if ($page->keyword_collision_score !== null) {
            $line .= ' (collision '.round($page->keyword_collision_score, 2).')';
        }

        return $line;
    }

    private function vol(Spoke $page): string
    {
        return $page->volume === null ? '' : '  vol '.number_format((int) $page->volume);
    }

    private function nest(Spoke $section): string
    {
        return $section->arrangement_score !== null ? '  (nest '.round($section->arrangement_score, 2).')' : '';
    }
}
