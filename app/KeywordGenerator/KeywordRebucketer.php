<?php

namespace App\KeywordGenerator;

use App\Build\SiloReconciler;
use App\Build\SiloRuleSetDeriver;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * Re-files a site's UNASSIGNED keywords (`silo_id = null`) into silos via rule_set matching — the
 * repair for keywords orphaned when a silo was removed (e.g. {@see SiloReconciler} nulls a
 * deleted stale silo's keywords, which then surface in the board's "Unassigned" band). Runs each
 * unassigned keyword's query through the {@see Bucketer} against the current silos' rule_sets and pins
 * the best match; a keyword that matches no silo stays unassigned (an honest gap, not a forced home).
 *
 * Needs the silos to carry rule_sets ({@see SiloRuleSetDeriver}) — without them the Bucketer
 * has nothing to match on and nothing re-files.
 */
class KeywordRebucketer
{
    public function __construct(private readonly Bucketer $bucketer) {}

    /**
     * Assign unassigned keywords to silos by rule_set match.
     *
     * @return int the number of keywords re-filed
     */
    public function rebucket(Site $site): int
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        if ($silos->isEmpty()) {
            return 0;
        }

        $reassigned = 0;
        $unassigned = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNull('silo_id')
            ->get();

        foreach ($unassigned as $keyword) {
            $silo = $this->bucketer->bucket((string) $keyword->query, $silos);
            if ($silo !== null) {
                $keyword->forceFill(['silo_id' => $silo->id])->save();
                $reassigned++;
            }
        }

        return $reassigned;
    }
}
