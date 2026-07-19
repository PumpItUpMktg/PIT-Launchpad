<?php

namespace App\KeywordGenerator\Derive;

use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * Part 3 orchestrator — turns demand clusters into structure: enforce viability (merge thin →
 * {@see ViabilityMerger}), derive the silo tree as blueprint Spoke rows ({@see StructureDeriver}), map
 * services onto it ({@see ServiceStructureMapper}), and surface high-demand clusters with no service
 * ({@see DemandWithoutServiceReport}). The derived tree lands in the shape the unchanged Prune path
 * reads; page assignment (own page / fold / blog target) is the existing volume+intent rule downstream.
 */
final class DerivationPipeline
{
    public function __construct(
        private readonly ViabilityMerger $merger,
        private readonly StructureDeriver $deriver,
        private readonly ServiceStructureMapper $mapper,
        private readonly DemandWithoutServiceReport $report,
    ) {}

    public function derive(Site $site): DerivationResult
    {
        $survivors = $this->merger->merge($site);       // zero thin clusters at output
        $this->deriver->derive($site, $survivors);      // → Spoke rows on the blueprint
        $mapping = $this->mapper->map($site, $survivors);
        $findings = $this->report->for($site);

        Log::info('keyword-first derivation', [
            'site_id' => $site->id,
            'silos' => count($survivors),
            'services_mapped' => $mapping['mapped'],
            'services_flagged' => $mapping['flagged'],
            'demand_findings' => count($findings),
        ]);

        return new DerivationResult(count($survivors), $mapping['mapped'], $mapping['flagged'], count($findings));
    }
}
