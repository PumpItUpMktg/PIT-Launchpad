<?php

namespace App\Filament\Pages\Gathering;

use App\Filament\Concerns\ManagesPruneSurface;
use App\Guided\StepGate;
use App\Jobs\BuildStructure;
use App\Models\BlogTarget;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Operator\Coverage\TargetingBoard;
use App\Operator\Coverage\TargetQueue;
use Filament\Notifications\Notification;

/**
 * New Setup · Step 7 — Silos & keywords: the GENERATE phase after steps 1–6 gather. Consumes
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

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.gathering.silos-step';

    /** Prune is a mode inside this surface — toggled, never a separate menu item. */
    public bool $pruneMode = false;

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

    /** Queued blog targets the prune routed — consumed by Operate → Blog (the continuous half). */
    public function getBlogTargetCountProperty(): int
    {
        $site = $this->getSite();

        return $site === null ? 0 : BlogTarget::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->count();
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
