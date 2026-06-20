<?php

namespace App\Jobs;

use App\Interview\Arrange\AutoArrangeRunner;
use App\Interview\Expansion\ExpansionPersister;
use App\Interview\Expansion\SiloExpander;
use App\Interview\SiloSeed;
use App\Interview\Volume\VolumeGrounder;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Step 3's on-entry chain: expand the seed into the candidate tree (silo-gen, once), ground it
 * on real search volume (silo-volume), then auto-arrange (B→C→A→D→E). Drives the site's
 * `structure_status` building → ready (or failed) so the guided page can poll. Idempotent: skips
 * expansion when spokes already exist, so a re-entry just re-grounds + re-arranges (the §10
 * decision-preservation twin keeps confirmed structure intact).
 */
class BuildStructure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $siteId) {}

    public function handle(SiloExpander $expander, ExpansionPersister $persister, VolumeGrounder $grounder, AutoArrangeRunner $arranger): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $state = SetupState::query()->firstOrCreate(['site_id' => $this->siteId]);
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->first();

        if ($blueprint === null || ! is_array($blueprint->seed) || trim((string) ($blueprint->seed['trade'] ?? '')) === '') {
            $state->update(['structure_status' => 'failed']); // no seed — Step 1 incomplete

            return;
        }

        try {
            $hasSpokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->exists();
            if (! $hasSpokes) {
                $persister->persist($site, $expander->expand(SiloSeed::fromArray($blueprint->seed)));
            }

            $grounder->ground($site);
            $arranger->run($site);

            $state->update(['structure_status' => 'ready']);
        } catch (Throwable $e) {
            report($e);
            $state->update(['structure_status' => 'failed']);
        }
    }
}
