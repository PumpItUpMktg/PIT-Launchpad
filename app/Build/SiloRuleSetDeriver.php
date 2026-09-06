<?php

namespace App\Build;

use App\Enums\SpokeTag;
use App\KeywordGenerator\Bucketer;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Spoke;
use App\SiloCreator\RuleSet;
use Illuminate\Support\Collection;

/**
 * Gives a guided site's §4 silos a topical `rule_set` so §5 keyword discovery can ROUTE keywords into
 * them. Guided silos are born from the spoke tree ({@see GuidedEntityProjector}) with no rule_set — and
 * the {@see Bucketer} needs include/exclude patterns to bucket a discovered
 * keyword into a silo. Without this, discovery has nowhere to file keywords for a guided silo, so the
 * §4 board stays "thin" no matter how often the pipeline runs.
 *
 * Each silo's rule_set is derived from its own spokes (the same grouping the projector uses, silo name):
 *   - `include_patterns` — the BROAD routing terms: the pillar's head keyword (e.g. "sump pump",
 *     "crawl space") + the silo name, so a discovered "sump pump battery backup" buckets in on substring;
 *   - `seed_terms` — every spoke head keyword (the specific known targets);
 *   - `exclude_patterns` — none (geo-neutrality is already guaranteed by the expander's head keywords).
 *
 * Two jobs, both driven from the spokes that already exist:
 *   - NEW: a silo with no rule_set at all gets one derived from its spokes.
 *   - REPAIR: a silo whose rule_set exists but carries EMPTY `seed_terms` (an early include-only rule_set
 *     written before its spoke head_keywords existed — the deriver's old non-clobber guard then froze it
 *     forever) is back-filled: `seed_terms` filled from the spokes, `include_patterns` unioned, and the
 *     existing `exclude_patterns` preserved untouched. Only fires when the spokes actually yield seeds, so
 *     it never churns a silo it can't improve.
 *
 * A rule_set that is already COMPLETE (has seed_terms) is never overwritten.
 */
class SiloRuleSetDeriver
{
    /**
     * Derive + persist rule_sets for a site's silos that need one (new or repair).
     *
     * @return array{new: int, repair: int}
     */
    public function deriveForSite(Site $site): array
    {
        $new = 0;
        $repair = 0;
        foreach ($this->plan($site) as $entry) {
            $entry['silo']->forceFill(['rule_set' => $entry['ruleSet']->toArray()])->save();
            $entry['repair'] ? $repair++ : $new++;
        }

        return ['new' => $new, 'repair' => $repair];
    }

    /**
     * How many silos would get a new rule_set / a repair (no writes).
     *
     * @return array{new: int, repair: int}
     */
    public function previewForSite(Site $site): array
    {
        $new = 0;
        $repair = 0;
        foreach ($this->plan($site) as $entry) {
            $entry['repair'] ? $repair++ : $new++;
        }

        return ['new' => $new, 'repair' => $repair];
    }

    /**
     * The silos that need a rule_set written, each paired with the rule_set and whether it's a repair.
     * A silo with a COMPLETE rule_set (non-empty seed_terms) is skipped; a silo with no derivable terms
     * is skipped; a repair candidate (empty seed_terms) is skipped unless its spokes actually yield seeds.
     *
     * @return list<array{silo: Silo, ruleSet: RuleSet, repair: bool}>
     */
    private function plan(Site $site): array
    {
        $spokesBySilo = Spoke::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('tag', '!=', SpokeTag::Fringe->value)
            ->get()
            ->groupBy(fn (Spoke $s): string => $this->siloKey($s));

        $plan = [];
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        foreach ($silos as $silo) {
            $existing = is_array($silo->rule_set) ? $silo->rule_set : [];
            $hasRuleSet = $existing !== [];
            $seedTerms = is_array($existing['seed_terms'] ?? null) ? $existing['seed_terms'] : [];

            if ($hasRuleSet && $seedTerms !== []) {
                continue; // already complete — never clobber
            }

            $spokes = $spokesBySilo->get(mb_strtolower(trim((string) $silo->name))) ?? collect();
            $derived = $this->build((string) $silo->name, $spokes);
            if ($derived->isEmpty()) {
                continue; // no spokes / no terms — nothing to route on
            }

            if ($hasRuleSet) {
                // REPAIR — only when the spokes actually yield seeds (else there's nothing to back-fill).
                if ($derived->seedTerms === []) {
                    continue;
                }
                $plan[] = ['silo' => $silo, 'ruleSet' => $this->repaired($existing, $derived), 'repair' => true];

                continue;
            }

            $plan[] = ['silo' => $silo, 'ruleSet' => $derived, 'repair' => false];
        }

        return $plan;
    }

    /**
     * Merge a freshly-derived rule_set onto an existing (empty-seed) one: take the derived seed_terms,
     * UNION include_patterns (existing first), and KEEP the existing exclude_patterns untouched.
     *
     * @param  array<string, mixed>  $existing
     */
    private function repaired(array $existing, RuleSet $derived): RuleSet
    {
        $existingInclude = is_array($existing['include_patterns'] ?? null)
            ? array_map('strval', $existing['include_patterns'])
            : [];
        $existingExclude = is_array($existing['exclude_patterns'] ?? null)
            ? array_map('strval', $existing['exclude_patterns'])
            : [];

        return new RuleSet(
            seedTerms: $derived->seedTerms,
            includePatterns: array_values(array_unique([...$existingInclude, ...$derived->includePatterns])),
            excludePatterns: array_values($existingExclude),
        );
    }

    /**
     * @param  Collection<int, Spoke>  $spokes
     */
    private function build(string $siloName, Collection $spokes): RuleSet
    {
        $pillarHead = $this->norm((string) ($spokes->where('is_pillar', true)->pluck('head_keyword')->first() ?? ''));

        // Broad routing terms — the head noun phrase + the silo name (deduped, non-empty).
        $include = array_values(array_unique(array_filter([$pillarHead, $this->norm($siloName)])));

        // Specific known targets — every spoke head keyword.
        $seeds = $spokes->pluck('head_keyword')
            ->map(fn ($h): string => $this->norm((string) $h))
            ->filter(fn (string $t): bool => $t !== '')
            ->unique()
            ->values()
            ->all();

        return new RuleSet($seeds, $include, []);
    }

    /** A spoke's silo grouping key — its `silo` (set for every spoke by the persister), else its own name. */
    private function siloKey(Spoke $spoke): string
    {
        $silo = trim((string) $spoke->silo);

        return mb_strtolower($silo !== '' ? $silo : (string) $spoke->name);
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
