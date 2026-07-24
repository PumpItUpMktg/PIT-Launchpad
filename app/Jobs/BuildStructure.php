<?php

namespace App\Jobs;

use App\Build\ServiceStructureWriter;
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
use Illuminate\Support\Str;
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

    public function handle(SiloExpander $expander, ExpansionPersister $persister, VolumeGrounder $grounder, AutoArrangeRunner $arranger, KeywordFirstBuilder $keywordFirst, ServiceStructureWriter $structureWriter): void
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
            if ($this->hasAuthoredGrouping($site)) {
                // Author-declared structure: the operator grouped their services (services-entry), so the
                // tree is DECLARED, not guessed. The deterministic writer replaces the AI expander; the
                // arranger is skipped (nothing to auto-arrange — the routing is explicit), volume still
                // grounds for scoring. Idempotent: re-run replaces the spoke set from the current tree.
                $structureWriter->write($site);
                $this->enrich($site, $grounder, null);
            } elseif (config('launchpad.keyword_first.enabled')) {
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

                $this->enrich($site, $grounder, $arranger);
            }

            $state->update(['structure_status' => 'ready', 'structure_error' => null]);
        } catch (Throwable $e) {
            // A failure to PRODUCE the tree (expand/write threw) — surface the reason, not a bare "failed".
            report($e);
            $state->update(['structure_status' => 'failed', 'structure_error' => Str::limit($e->getMessage(), 480)]);
        }
    }

    /**
     * Best-effort scoring enrichment AFTER the tree is written: refresh search volume (and, on the AI
     * path, auto-arrange). The structure itself is already persisted, so a provider hiccup here — e.g. a
     * slow/failing DataForSEO volume pull on the synchronous rebuild — must NOT discard a good tree. The
     * failure is logged; volume/arrangement simply stays un-refreshed until the next run.
     */
    private function enrich(Site $site, VolumeGrounder $grounder, ?AutoArrangeRunner $arranger): void
    {
        try {
            $grounder->ground($site);
            $arranger?->run($site);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether the operator has authored a grouping in the services entry — i.e. any service is nested
     * under a parent. When true the structure is DECLARED and the deterministic
     * {@see ServiceStructureWriter} drives the build (the AI expander is demoted to the "Suggest
     * grouping" helper). A flat, ungrouped service list keeps the AI-expansion path unchanged.
     */
    private function hasAuthoredGrouping(Site $site): bool
    {
        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('parent_service_id')
            ->exists();
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
