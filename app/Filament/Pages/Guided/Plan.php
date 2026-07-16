<?php

namespace App\Filament\Pages\Guided;

use App\Build\ApproveAndBuild;
use App\Build\InventoryPlan;
use App\Enums\SetupStep;
use App\Enums\StandardPageType;
use App\Filament\Pages\SiloPrune;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Interview\Arrange\FlagResolver;
use App\Interview\Prune\PruneEngine;
use App\Interview\Prune\PruneRow;
use App\Jobs\BuildStructure;
use App\Locations\LocalRelevance;
use App\Models\ArrangementFlag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Standard\StandardPages;
use Filament\Notifications\Notification;

/**
 * Step 5 · Your website plan (setup-redesign relay: Structure + Page inventory + Finalize merged
 * into ONE page). The page cards lead — every page the plan builds, grouped foundation / service
 * / town — because that's what the owner actually decides on. The structure tree is demoted to a
 * collapsed "Adjust structure" panel (the same arranged tree + flags the old Structure step
 * showed; the full editor stays at {@see SiloPrune}), and Approve is the button at the bottom,
 * not a step.
 *
 * On first entry the structure engine builds exactly as before ({@see BuildStructure} with the
 * building/ready/failed state machine). Approve finalizes the structure through the proven
 * {@see PruneEngine} when it hasn't been finalized yet (empty decision-set = keep everything
 * arranged, the stated-service floor), assembles the manifest, materializes the planned pages,
 * and hands off to Grow. Flags still block approve until resolved (§4b).
 *
 * @property-read array<string, mixed> $inventory
 * @property-read array<string, list<PruneRow>> $bySilo
 * @property-read array<int, array{id: string, type: string, message: string, score: float|null, spoke: string}> $arrangeFlags
 * @property-read array{now: int, reserve: int, ready: int} $drip
 */
class Plan extends GuidedPage
{
    protected static ?string $slug = 'setup/plan';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Your website plan';

    protected string $view = 'filament.guided.plan';

    public bool $localize = true;

    public int $townPagePace = 5;

    public bool $freshContent = true;

    public function step(): SetupStep
    {
        return SetupStep::Plan;
    }

    public function mount(): void
    {
        parent::mount(); // resolve site

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $state = app(StepGate::class)->state($site);

        // Structure engine-on-entry (absorbed from the old Structure step): an existing tree is
        // ready; a missing one marks building — the blade's wire:init drives the synchronous
        // chain (visible wire:loading indicator, no queue worker required).
        $hasSpokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists();
        if ($hasSpokes) {
            if ($state->structure_status !== 'ready') {
                $state->update(['structure_status' => 'ready']);
            }
        } elseif ($state->structure_status === null && $this->hasSeed($site)) {
            $state->update(['structure_status' => 'building']);
        }

        // First visit: default the offerable optionals ON, so the plan starts fully selected
        // (curate by deselecting). Respects any later operator choice (only seeds when untouched).
        if ($state->standard_pages === null) {
            $standard = app(StandardPages::class);
            foreach ($standard->offerable($site) as $row) {
                $standard->setAccepted($site, $row['type'], true);
            }
        }

        // Seed the population-based town selection so the plan's "build now vs. reserve" split
        // is real. No-op once the operator has curated the pool.
        app(LocalRelevance::class)->seedInitialSelection($site);

        // Build-config defaults (absorbed from the old Finalize step). A just-created state row
        // (free-tab first visit) hasn't loaded its column defaults yet — fall back explicitly.
        $this->localize = $state->localize ?? true;
        $this->townPagePace = $state->town_page_pace ?? 5;
        $this->freshContent = $state->fresh_content ?? true;
    }

    /**
     * Run the build chain synchronously (silo-gen → silo-volume → auto-arrange). Triggered by the
     * blade's wire:init on entry, so a wire:loading spinner shows while it runs and clears on
     * return — no queue worker to depend on. Idempotent: a ready structure is left untouched.
     */
    public function runBuild(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (app(StepGate::class)->state($site)->structure_status === 'ready') {
            return;
        }

        BuildStructure::dispatchSync($site->id); // the job stamps ready/failed itself
    }

    public function getStatusProperty(): ?string
    {
        $site = $this->getSite();

        return $site === null ? null : app(StepGate::class)->state($site)->structure_status;
    }

    /** Retry a failed build — re-runs the chain synchronously. */
    public function rebuild(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        app(StepGate::class)->state($site)->update(['structure_status' => 'building']);
        BuildStructure::dispatchSync($site->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInventoryProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['counts' => ['total' => 0, 'foundation' => 0, 'service' => 0, 'location_now' => 0, 'reserve' => 0], 'foundation' => [], 'silos' => [], 'tiers' => []]
            : app(InventoryPlan::class)->for($site);
    }

    /**
     * The arranged tree for the "Adjust structure" panel — the old Structure step's view, demoted.
     *
     * @return array<string, list<PruneRow>>
     */
    public function getBySiloProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(PruneEngine::class)->plan($site)->bySilo();
    }

    /**
     * @return array<int, array{id: string, type: string, message: string, score: float|null, spoke: string}>
     */
    public function getArrangeFlagsProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return [];
        }

        $names = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name', 'id');

        return ArrangementFlag::query()
            ->where('site_id', $site->id)
            ->orderBy('type')
            ->get()
            ->map(fn (ArrangementFlag $f) => [
                'id' => $f->id,
                'type' => $f->type->label(),
                'message' => $f->message,
                'score' => $f->score,
                'spoke' => (string) ($names[$f->spoke_id] ?? ''),
            ])
            ->all();
    }

    public function acceptFlag(string $id): void
    {
        $this->resolveFlag($id, accept: true);
    }

    public function dismissFlag(string $id): void
    {
        $this->resolveFlag($id, accept: false);
    }

    public function getUnresolvedFlagCountProperty(): int
    {
        return $this->unresolvedFlags();
    }

    /** Approve needs a ready structure with every judgment-call flag resolved. */
    public function getCanApproveProperty(): bool
    {
        return $this->getStatusProperty() === 'ready'
            && $this->unresolvedFlags() === 0
            && $this->getInventoryProperty()['counts']['service'] > 0;
    }

    /** Toggle an optional standard page into/out of the build manifest (curates the build). */
    public function toggleStandard(string $type): void
    {
        $site = $this->getSite();
        $pageType = StandardPageType::tryFrom($type);
        if ($site === null || $pageType === null) {
            return;
        }

        $standard = app(StandardPages::class);
        $current = collect($standard->offerable($site))->firstWhere('type', $pageType);
        $standard->setAccepted($site, $pageType, ! ($current['accepted'] ?? false));
    }

    /**
     * The per-business location-page drip summary: how many towns build now vs. sit in reserve,
     * and how many reserve towns have already earned enough local relevance to drip live.
     *
     * @return array{now: int, reserve: int, ready: int}
     */
    public function getDripProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return ['now' => 0, 'reserve' => 0, 'ready' => 0];
        }

        $rows = collect(app(LocalRelevance::class)->forSite($site));
        $reserve = $rows->where('selected', false);

        return [
            'now' => $rows->where('selected', true)->count(),
            'reserve' => $reserve->count(),
            'ready' => $reserve->where('ready', true)->count(),
        ];
    }

    public function toggleLocalize(): void
    {
        $this->localize = ! $this->localize;
    }

    public function toggleFreshContent(): void
    {
        $this->freshContent = ! $this->freshContent;
    }

    public function approveAndBuild(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $state = app(StepGate::class)->state($site);

        // An already-finalized structure may re-approve regardless of the engine state (a
        // returning tenant); an unfinalized one must wait for the build to finish.
        if (! $state->structure_finalized && $this->getStatusProperty() !== 'ready') {
            Notification::make()->title('Still building your plan…')->warning()->send();

            return;
        }

        $count = $this->unresolvedFlags();
        if ($count > 0) {
            Notification::make()
                ->title('Resolve the flagged items first.')
                ->body($count.' item(s) still need your input — open "Adjust structure".')
                ->warning()
                ->send();

            return;
        }

        // The whole finalize → config → manifest → materialize → category-sync → wizard-complete
        // sequence lives in the shared {@see ApproveAndBuild} core (also run by the new Setup's
        // Launch step), so both surfaces launch identically.
        app(ApproveAndBuild::class)->approve($site, $this->localize, $this->townPagePace, $this->freshContent);

        Notification::make()->title('Plan approved — your pages are ready.')->success()->send();
        $this->redirect(SetupStep::Grow->pageClass()::getUrl());
    }

    private function resolveFlag(string $id, bool $accept): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $flag = ArrangementFlag::query()->where('site_id', $site->id)->whereKey($id)->first();
        if ($flag === null) {
            return;
        }

        $resolver = app(FlagResolver::class);
        $ok = $accept ? $resolver->accept($site, $flag) : $resolver->dismiss($site, $flag);

        Notification::make()
            ->title($ok ? ($accept ? 'Accepted.' : 'Dismissed.') : 'Could not resolve that flag.')
            ->{$ok ? 'success' : 'warning'}()
            ->send();
    }

    private function unresolvedFlags(): int
    {
        $site = $this->getSite();

        return $site === null ? 0 : ArrangementFlag::query()->where('site_id', $site->id)->count();
    }

    private function hasSeed(Site $site): bool
    {
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

        return $blueprint !== null && is_array($blueprint->seed) && trim((string) ($blueprint->seed['trade'] ?? '')) !== '';
    }
}
