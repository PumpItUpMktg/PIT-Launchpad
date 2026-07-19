<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\ServiceSiloRole;
use App\Filament\Concerns\ManagesPruneSurface;
use App\Guided\StepGate;
use App\Interview\Prune\PruneEngine;
use App\Interview\Prune\PruneRow;
use App\Jobs\BuildStructure;
use App\KeywordGenerator\Derive\DemandWithoutServiceReport;
use App\Models\BlogTarget;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Operator\Coverage\TargetingBoard;
use App\Operator\Coverage\TargetQueue;
use Filament\Notifications\Notification;

/**
 * New Setup · Step 8 — Silos & keywords: the GENERATE phase after steps 1–7 gather. Consumes
 * exactly what gathering produced (trade, stated services, coverage) and its output is the
 * structure everything downstream builds on: generate the silo blueprint from the seed →
 * prune / fold / route (a MODE inside this surface, not its own menu item — prune without
 * silo context is meaningless) → blog targets seeded into the queue Operate's Blog drawer
 * consumes. Heavy once per tenant, then touched only on real business change (a new service
 * line → re-ground, re-prune) — Setup-shaped by frequency.
 *
 * @property-read array{silos: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, unassigned_total: int, threshold: int} $board
 * @property-read bool $hasCandidates
 * @property-read list<string> $deadSilos
 * @property-read list<array{id: string, type: string, message: string, score: float|null, spoke: string}> $arrangeFlags
 */
class SilosStep extends GatheringPage
{
    use ManagesPruneSurface;

    protected static ?string $slug = 'setup2/silos';

    protected static ?string $navigationLabel = 'Silos & keywords';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.gathering.silos-step';

    /** Prune is a mode inside this surface — toggled, never a separate menu item. */
    public bool $pruneMode = false;

    /** Armed after the first Generate click on a confirmed structure — the destructive-regen warning. */
    public bool $regenArmed = false;

    protected function pruneSite(): ?Site
    {
        return $this->getSite();
    }

    protected function afterSiteResolved(): void
    {
        // Switching sites always drops back to the cards view with a clean decision-set.
        $this->reset(['pruneMode', 'started', 'finalized', 'spokeDecisions', 'siloDecisions']);
    }

    /** The blueprint seed steps 1–6 gathered (Business trade field / interview extraction). */
    public function getHasSeedProperty(): bool
    {
        $site = $this->getSite();
        if ($site === null) {
            return false;
        }

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

        return $blueprint !== null && is_array($blueprint->seed) && trim((string) ($blueprint->seed['trade'] ?? '')) !== '';
    }

    public function getHasSpokesProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists();
    }

    /** The structure engine's building/ready/failed state (shared with the guided Plan step). */
    public function getStructureStatusProperty(): ?string
    {
        $site = $this->getSite();

        return $site === null ? null : app(StepGate::class)->state($site)->structure_status;
    }

    public function getBlueprintConfirmedProperty(): bool
    {
        $site = $this->getSite();
        if ($site === null) {
            return false;
        }

        return SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    /**
     * Generate (or re-ground) the structure from the gathered seed — the same synchronous
     * chain as the guided Plan step ({@see BuildStructure}: silo-gen once, then re-ground +
     * re-arrange; the §10 decision-preservation twin keeps confirmed structure intact, so
     * re-running after a new service line is safe).
     */
    public function generate(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (! $this->getHasSeedProperty()) {
            Notification::make()->warning()
                ->title('No seed yet')
                ->body('Capture the trade on the Business step (or run the interview) first — the structure is built from it.')
                ->send();

            return;
        }

        // Destructive-regen guard (keyword-first v1): re-deriving replaces the tree, so an operator
        // must confirm before regenerating a CONFIRMED structure. First click arms the warning; the
        // second (or a fresh, unconfirmed structure) proceeds.
        if (config('launchpad.keyword_first.enabled') && $this->getBlueprintConfirmedProperty() && ! $this->regenArmed) {
            $this->regenArmed = true;
            Notification::make()->warning()
                ->title('Regenerating replaces your confirmed structure')
                ->body('Re-deriving from demand rebuilds the tree — you\'ll re-prune. Click Generate again to confirm.')
                ->send();

            return;
        }
        $this->regenArmed = false;

        app(StepGate::class)->state($site)->update(['structure_status' => 'building']);
        BuildStructure::dispatchSync($site->id); // stamps ready/failed itself

        $status = $this->getStructureStatusProperty();
        Notification::make()
            ->{$status === 'ready' ? 'success' : 'warning'}()
            ->title($status === 'ready' ? 'Structure generated' : 'Generation failed — check the logs and retry.')
            ->send();

        // A regenerate invalidates any in-progress decision-set view.
        $this->reset(['pruneMode', 'started', 'finalized', 'spokeDecisions', 'siloDecisions']);
    }

    /** Enter prune mode — seeds the decision-set from the candidate tree. */
    public function openPrune(): void
    {
        if (! $this->getHasCandidatesProperty()) {
            Notification::make()->warning()->title('Generate the structure first.')->send();

            return;
        }

        $this->start();
        $this->pruneMode = true;
    }

    public function closePrune(): void
    {
        $this->reset(['pruneMode', 'started', 'finalized']);
    }

    /**
     * The silo cards — silo by silo, keyword targets in queue order, covered/gap split
     * (same board as the legacy Targeting page).
     *
     * @return array{silos: list<array<string, mixed>>, unassigned: list<array<string, mixed>>, unassigned_total: int, threshold: int}
     */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['silos' => [], 'unassigned' => [], 'unassigned_total' => 0, 'threshold' => 0]
            : app(TargetingBoard::class)->for($site);
    }

    /**
     * The generated structure tree, grouped by silo — the READ-ONLY display of what generate /
     * re-ground produced (spokes live on the blueprint; §4 Silo + Keyword rows only exist after
     * launch + discovery, so the keyword board alone would show nothing here). Prune mode edits.
     *
     * @return array<string, list<PruneRow>>
     */
    public function getTreeProperty(): array
    {
        $site = $this->getSite();

        return $site === null || ! $this->getHasSpokesProperty()
            ? []
            : app(PruneEngine::class)->plan($site)->bySilo();
    }

    /** Queued blog targets the prune routed — consumed by Operate → Blog (the continuous half). */
    public function getBlogTargetCountProperty(): int
    {
        $site = $this->getSite();

        return $site === null ? 0 : BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->count();
    }

    /**
     * The demand-without-service findings (keyword-first): high-demand clusters with no matching
     * service — "Crawl space encapsulation — 3,360/mo — no service. Add it?". The BD output of the
     * keyword-first pipeline; empty (and hidden) when the pipeline isn't enabled.
     *
     * @return list<array{cluster_id: string, label: string|null, head_term: string|null, volume: int|null}>
     */
    public function getDemandReportProperty(): array
    {
        $site = $this->getSite();
        if ($site === null || ! config('launchpad.keyword_first.enabled')) {
            return [];
        }

        return app(DemandWithoutServiceReport::class)->for($site);
    }

    /** Create a service from a demand finding — links it straight into the structure (its home cluster). */
    public function createServiceFromDemand(string $clusterId): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $cluster = KeywordCluster::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($clusterId)->first();
        if ($cluster === null) {
            return;
        }

        $name = trim((string) ($cluster->head_term ?? $cluster->label ?? ''));
        if ($name === '') {
            return;
        }

        Service::withoutGlobalScope(SiteScope::class)->firstOrCreate(
            ['site_id' => $site->id, 'name' => $name],
            ['silo_role' => ServiceSiloRole::Supporting, 'structure_home_cluster_id' => $cluster->id, 'structure_home_flagged' => false],
        );

        Notification::make()->success()->title("Added '{$name}' — linked into its silo.")->send();
    }

    /** Dismiss a demand finding — it stops surfacing on the report (the demand still exists in the tree). */
    public function dismissDemand(string $clusterId): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        KeywordCluster::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($clusterId)
            ->update(['demand_dismissed' => true]);
    }

    public function promote(string $keywordId): void
    {
        $keyword = $this->ownedKeyword($keywordId);
        if ($keyword !== null) {
            app(TargetQueue::class)->promote($keyword);
        }
    }

    public function demote(string $keywordId): void
    {
        $keyword = $this->ownedKeyword($keywordId);
        if ($keyword !== null) {
            app(TargetQueue::class)->demote($keyword);
        }
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        if (! $this->getHasSpokesProperty()) {
            return $this->getHasSeedProperty()
                ? ['state' => 'attention', 'label' => 'Seed ready — generate the structure']
                : ['state' => 'empty', 'label' => 'Empty — gather the trade first (Business / Interview)'];
        }

        return $this->getBlueprintConfirmedProperty()
            ? ['state' => 'complete', 'label' => 'Structure confirmed']
            : ['state' => 'attention', 'label' => 'Generated — prune & finalize'];
    }

    private function ownedKeyword(string $keywordId): ?Keyword
    {
        return $this->siteId === null ? null : Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($keywordId)
            ->first();
    }
}
