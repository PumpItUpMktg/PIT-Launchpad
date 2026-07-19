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
 * Idempotent + non-destructive: a silo that already carries a rule_set (e.g. a §4-committed catalog silo)
 * is never overwritten.
 */
class SiloRuleSetDeriver
{
    /**
     * Derive + persist rule_sets for a site's silos that lack one.
     *
     * @return int the number of silos given a rule_set
     */
    public function deriveForSite(Site $site): int
    {
        $updated = 0;
        foreach ($this->plan($site) as [$silo, $ruleSet]) {
            $silo->forceFill(['rule_set' => $ruleSet->toArray()])->save();
            $updated++;
        }

        return $updated;
    }

    /** How many silos would get a rule_set (no writes). */
    public function previewForSite(Site $site): int
    {
        return count($this->plan($site));
    }

    /**
     * The silos that lack a rule_set and have terms to route on, each paired with the rule_set it would
     * get. Non-destructive: a silo already carrying a rule_set (e.g. a §4-committed catalog silo) is
     * skipped, and a silo with no derivable terms is skipped.
     *
     * @return list<array{0: Silo, 1: RuleSet}>
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
            if (! empty($silo->rule_set)) {
                continue; // never clobber a committed rule_set
            }

            $spokes = $spokesBySilo->get(mb_strtolower(trim((string) $silo->name))) ?? collect();
            $ruleSet = $this->build((string) $silo->name, $spokes);
            if ($ruleSet->isEmpty()) {
                continue; // no spokes / no terms — nothing to route on
            }

            $plan[] = [$silo, $ruleSet];
        }

        return $plan;
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
