<?php

namespace App\Jobs;

use App\Interview\Arrange\AutoArrangeRunner;
use App\Interview\Expansion\ExpansionPersister;
use App\Interview\Expansion\SiloExpander;
use App\Interview\SiloSeed;
use App\Interview\Volume\VolumeGrounder;
use App\KeywordGenerator\KeywordFirstBuilder;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
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

    public function handle(SiloExpander $expander, ExpansionPersister $persister, VolumeGrounder $grounder, AutoArrangeRunner $arranger, KeywordFirstBuilder $keywordFirst): void
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
            if (config('launchpad.keyword_first.enabled')) {
                // Keyword-first: accumulate demand → cluster → derive the tree → arrange. Structure is
                // shaped by measured demand, not the catalog. Regeneration replaces the candidate tree.
                $keywordFirst->build($site);
            } else {
                $hasSpokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->exists();
                if (! $hasSpokes) {
                    $seed = SiloSeed::fromArray($blueprint->seed);
                    // Bounded mode: organize ONLY the stated services into silos (no invented services).
                    if (! empty($blueprint->seed['bound_to_services'])) {
                        $seed = $seed->withBoundedServices($this->statedServiceNames($site));
                    }
                    $persister->persist($site, $expander->expand($seed));
                }

                $grounder->ground($site);
                $arranger->run($site);
            }

            $state->update(['structure_status' => 'ready']);
        } catch (Throwable $e) {
            report($e);
            $state->update(['structure_status' => 'failed']);
        }
    }

    /**
     * The site's stated service names — the authoritative scope for bounded generation.
     *
     * @return list<string>
     */
    private function statedServiceNames(Site $site): array
    {
        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn ($name): string => trim((string) $name))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }
}
