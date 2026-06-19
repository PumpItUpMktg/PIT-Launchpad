<?php

namespace App\Filament\Pages;

use App\Enums\PruneOutcome;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeTag;
use App\Interview\Prune\PruneEngine;
use App\Interview\Prune\PruneRow;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Phase 4 PR-B — the prune surface (operator admin, sibling to Owner Interview). Walks
 * the candidate SiloBlueprint into a confirmed one. The asymmetry of effort made visual:
 * grouped by silo, batch-confirm the stated core, focus on the volume-sorted lean-ins,
 * quick-route the fringe.
 *
 * The UI owns INTERACTION only — it collects a decision-set (per-spoke outcome / re-tag /
 * granularity + silo-level fold / rename / confirm) and applies it through the proven
 * {@see PruneEngine}; the routing table, hard gate, and finalize rules live in the engine.
 * The in-progress decision-set persists to the blueprint (draft/resume); Finalize is the
 * explicit commit. Data-driven: every number/note comes from the tree already on disk —
 * no live-model call.
 *
 * @property-read array<string, string> $siteOptions
 * @property-read bool $hasCandidates
 * @property-read list<string> $deadSilos
 */
class SiloPrune extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scissors';

    protected static ?string $navigationLabel = 'Prune';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.silo-prune';

    public ?string $siteId = null;

    public bool $started = false;

    public bool $finalized = false;

    /** @var array<string, array{outcome?: string, tag?: string, granularity?: string, fold_into?: string|null}> keyed by spoke id */
    public array $spokeDecisions = [];

    /** @var array<string, array{rename?: string, fold_into?: string, confirm?: bool}> keyed by silo name */
    public array $siloDecisions = [];

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    public function getHasCandidatesProperty(): bool
    {
        return $this->siteId !== null && SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereHas('spokes')
            ->exists();
    }

    public function start(): void
    {
        $site = $this->site();
        if ($site === null) {
            Notification::make()->title('Pick a site with a candidate tree first.')->warning()->send();

            return;
        }

        $this->reset(['spokeDecisions', 'siloDecisions', 'finalized']);
        $this->seedDecisions($site);
        $this->started = true;
    }

    /**
     * Apply the pending decisions and re-derive the tree WITHOUT committing (the "Update"
     * action, before Finalize): persist the silo folds / promote-demote / fold-target / re-tag
     * to the spokes + the draft, then re-seed from the now-restructured persisted state so a
     * folded silo collapses into its absorber and the summaries recompute. Reuses the same
     * draft snapshot the re-ground preservation (§10) reads — clicking Update IS confirming the
     * current decisions. Finalize stays the terminal commit (skip pending + stamp + clear draft).
     */
    public function applyUpdate(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $this->engine()->applyDecisionSet($site, $this->decisionSet());
        $this->engine()->saveDraft($site, ['spokes' => $this->spokeDecisions, 'silos' => $this->siloDecisions]);
        $this->seedDecisions($site); // re-derive layout + toggles from persisted state

        Notification::make()->title('Updated — tree re-derived.')->success()->send();
    }

    /**
     * Seed the decision-set from the pre-decided defaults, then resume the saved draft over them
     * (the draft wins, so an Update/re-ground never resets an owner choice). Shared by the
     * initial open and the Update re-derive.
     */
    private function seedDecisions(Site $site): void
    {
        // Pre-decided defaults the owner reviews (opt-out), not a blank slate: pillar → hub page,
        // core ≥ bar → own page, core < bar → fold into pillar, supporting → fold into most-related
        // core. Stated services are offered (the floor); only page-vs-section differs. Fringe stays
        // a handoff (blank outcome). The tag rides along as metadata.
        $plan = $this->engine()->plan($site);
        $defaults = $plan->defaults();
        $this->spokeDecisions = [];
        foreach ($plan->rows as $row) {
            $default = $defaults[$row->id] ?? null;
            if ($default === null) { // fringe — Routing handoff
                $this->spokeDecisions[$row->id] = ['outcome' => '', 'tag' => $row->tag->value, 'granularity' => $row->granularity->value, 'fold_into' => ''];

                continue;
            }
            $this->spokeDecisions[$row->id] = [
                'outcome' => PruneOutcome::Offer->value,
                'tag' => $row->tag->value,
                'granularity' => $default['disposition'] === 'fold' ? SpokeGranularity::Folded->value : SpokeGranularity::OwnPage->value,
                'fold_into' => $default['fold_into'] ?? '',
            ];
        }

        $draft = $this->engine()->loadDraft($site);
        $this->spokeDecisions = array_replace($this->spokeDecisions, is_array($draft['spokes'] ?? null) ? $draft['spokes'] : []);
        $this->siloDecisions = is_array($draft['silos'] ?? null) ? $draft['silos'] : [];
    }

    /**
     * The canonical relocate (one mutation, three triggers: the fold dropdown, the own-page↔fold
     * toggle, and drag). Moves a spoke and re-derives the tree so it physically re-nests:
     *  - `own_page` → promote to its own page (clears the fold target);
     *  - `core` (targetId = a core spoke id) → fold into that core page, re-homing to its silo if cross-silo;
     *  - `silo` (targetId = a silo name) → fold into that silo at its default (most-related) core;
     *  - `fold` → demote into its own silo's default core.
     * Persists to the draft (so it survives Update / a re-ground — §10) and re-renders.
     */
    public function moveSpoke(string $spokeId, string $targetType, ?string $targetId = null): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $plan = $this->engine()->plan($site);
        [$targetSilo, $granularity, $foldInto] = match ($targetType) {
            'own_page' => [null, SpokeGranularity::OwnPage, null],
            'core' => [$this->siloOfSpoke($plan, (string) $targetId), SpokeGranularity::Folded, $targetId],
            'silo' => [(string) $targetId, SpokeGranularity::Folded, $this->siloDefaultCore($plan, (string) $targetId)],
            'fold' => [null, SpokeGranularity::Folded, $this->siloDefaultCore($plan, $this->siloOfSpoke($plan, $spokeId) ?? '')],
            default => [null, SpokeGranularity::OwnPage, null],
        };

        if (! $this->engine()->moveSpoke($site, $spokeId, $targetSilo, $granularity, $foldInto)) {
            return;
        }

        // Mirror the decision into the draft so the re-derive (and a later re-ground) keeps it.
        $this->spokeDecisions[$spokeId] = [
            'outcome' => PruneOutcome::Offer->value,
            'tag' => $this->spokeDecisions[$spokeId]['tag'] ?? '',
            'granularity' => $granularity->value,
            'fold_into' => $foldInto ?? '',
        ];
        $this->persistAndRederive($site);
    }

    /** Fold an entire silo into another (the dropdown / a silo-header drop) — canonical + re-derive. */
    public function foldSiloInto(string $silo, string $targetSilo): void
    {
        $site = $this->site();
        if ($site === null || $silo === '' || $targetSilo === '' || $silo === $targetSilo) {
            return;
        }

        $this->engine()->foldSilo($site, $silo, $targetSilo);
        $this->siloDecisions[$silo]['fold_into'] = $targetSilo;
        $this->persistAndRederive($site);
    }

    /** Auto-apply a silo fold the moment the "fold silo into…" dropdown changes. */
    public function updatedSiloDecisions(mixed $value, ?string $key = null): void
    {
        if (is_string($key) && is_string($value) && $value !== '' && str_ends_with($key, '.fold_into')) {
            $this->foldSiloInto(substr($key, 0, -strlen('.fold_into')), $value);
        }
    }

    /** Auto-apply a spoke promote/demote or fold-target change the moment its control changes. */
    public function updatedSpokeDecisions(mixed $value, ?string $key = null): void
    {
        if (! is_string($key)) {
            return;
        }
        if (str_ends_with($key, '.granularity')) {
            $id = substr($key, 0, -strlen('.granularity'));
            $this->moveSpoke($id, $value === SpokeGranularity::OwnPage->value ? 'own_page' : 'fold');
        } elseif (str_ends_with($key, '.fold_into') && is_string($value) && $value !== '') {
            $this->moveSpoke(substr($key, 0, -strlen('.fold_into')), 'core', $value);
        }
    }

    private function persistAndRederive(Site $site): void
    {
        $this->engine()->saveDraft($site, ['spokes' => $this->spokeDecisions, 'silos' => $this->siloDecisions]);
        $this->seedDecisions($site); // re-read the restructured tree + draft so the view re-nests
    }

    /** The silo a spoke currently belongs to (from the freshly-derived plan). */
    private function siloOfSpoke(\App\Interview\Prune\PrunePlan $plan, string $spokeId): ?string
    {
        foreach ($plan->rows as $row) {
            if ($row->id === $spokeId) {
                return $row->silo;
            }
        }

        return null;
    }

    /** A silo's default fold target — its most-related (highest-volume) core page, else its pillar. */
    private function siloDefaultCore(\App\Interview\Prune\PrunePlan $plan, string $silo): ?string
    {
        $rows = $plan->bySilo()[$silo] ?? [];
        $pillar = null;
        $bestCore = null;
        foreach ($rows as $row) {
            if ($row->isPillar) {
                $pillar = $row;

                continue;
            }
            if ($row->tag === SpokeTag::Core && ($bestCore === null || ($row->volume ?? -1) > ($bestCore->volume ?? -1))) {
                $bestCore = $row;
            }
        }

        return ($bestCore ?? $pillar)?->id;
    }

    /** Batch-confirm a silo's core: verify, don't deliberate. */
    public function confirmCore(string $silo): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        foreach ($this->engine()->plan($site)->bySilo()[$silo] ?? [] as $row) {
            if ($row->tag === SpokeTag::Core) {
                $this->spokeDecisions[$row->id]['outcome'] = PruneOutcome::Offer->value;
            }
        }
    }

    public function saveDraft(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $this->engine()->saveDraft($site, ['spokes' => $this->spokeDecisions, 'silos' => $this->siloDecisions]);
        Notification::make()->title('Draft saved — resume any time.')->success()->send();
    }

    public function finalize(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $result = $this->engine()->finalize($site, $this->decisionSet());
        $this->finalized = true;

        Notification::make()
            ->title('Blueprint confirmed')
            ->body("{$result['built']} pages will be built · {$result['skipped']} dropped.")
            ->success()
            ->send();
    }

    /**
     * Live disposition preview from the current decisions: own pages vs folded sections vs
     * dropped. `built` (pages + folded) and `pending`/`skipped` are kept for back-compat.
     *
     * @return array{built: int, pages: int, folded: int, dropped: int, pending: int, skipped: int}
     */
    public function getPreviewProperty(): array
    {
        $pages = 0;
        $folded = 0;
        $dropped = 0;
        $pending = 0;

        foreach ($this->decidableRows() as $row) {
            $decision = $this->spokeDecisions[$row->id] ?? [];
            $outcome = $decision['outcome'] ?? '';
            if ($outcome === '') {
                $pending++;

                continue;
            }
            if ($outcome === PruneOutcome::Skip->value) {
                $dropped++;

                continue;
            }
            if (($decision['granularity'] ?? '') === SpokeGranularity::Folded->value) {
                $folded++;
            } else {
                $pages++;
            }
        }

        return ['built' => $pages + $folded, 'pages' => $pages, 'folded' => $folded, 'dropped' => $dropped, 'pending' => $pending, 'skipped' => $dropped];
    }

    /**
     * Silos the engine flags as dead (advisory) — the operator confirms the fold via the
     * silo's fold-into control. See {@see PrunePlan::deadSilos()}.
     *
     * @return list<string>
     */
    public function getDeadSilosProperty(): array
    {
        $site = $this->site();

        return $site === null ? [] : $this->engine()->plan($site)->deadSilos();
    }

    /**
     * @return array<string, list<PruneRow>>
     */
    public function getBySiloProperty(): array
    {
        $site = $this->site();

        return $site === null ? [] : $this->engine()->plan($site)->bySilo();
    }

    /**
     * @return array<string, array{total: int, core: int, lean_ins: int, lean_in_volume: int, pending: int}>
     */
    public function getSummariesProperty(): array
    {
        $site = $this->site();

        return $site === null ? [] : $this->engine()->plan($site)->siloSummaries();
    }

    /**
     * @return list<PruneRow>
     */
    public function getFringeProperty(): array
    {
        $site = $this->site();

        return $site === null ? [] : $this->engine()->plan($site)->fringe();
    }

    /**
     * @return array<string, string>
     */
    public function getOutcomeOptionsProperty(): array
    {
        return collect(PruneOutcome::cases())->mapWithKeys(fn (PruneOutcome $o) => [$o->value => $o->label()])->all();
    }

    /**
     * @return array<string, string>
     */
    public function getTagOptionsProperty(): array
    {
        return collect([SpokeTag::Core, SpokeTag::Adjacent, SpokeTag::Connecting])
            ->mapWithKeys(fn (SpokeTag $t) => [$t->value => ucfirst($t->value)])->all();
    }

    /**
     * @return array<string, string>
     */
    public function getGranularityOptionsProperty(): array
    {
        return [SpokeGranularity::OwnPage->value => 'Own page', SpokeGranularity::Folded->value => 'Folded'];
    }

    /**
     * @return array{spokes: array<string, mixed>, silos: array<string, mixed>}
     */
    private function decisionSet(): array
    {
        $spokes = [];
        foreach ($this->spokeDecisions as $id => $d) {
            if (($d['outcome'] ?? '') === '') {
                continue; // undecided → finalize() drops it
            }
            $spec = ['outcome' => $d['outcome']];
            if (! empty($d['tag'])) {
                $spec['tag'] = $d['tag'];
            }
            if (! empty($d['granularity'])) {
                $spec['granularity'] = $d['granularity'];
            }
            if (array_key_exists('fold_into', $d)) {
                $spec['fold_into'] = $d['fold_into']; // '' → pillar (null) in the engine
            }
            $spokes[$id] = $spec;
        }

        $silos = [];
        foreach ($this->siloDecisions as $silo => $d) {
            $entry = [];
            if (! empty($d['rename'])) {
                $entry['rename'] = $d['rename'];
            }
            if (! empty($d['fold_into'])) {
                $entry['fold_into'] = $d['fold_into'];
            }
            if (! empty($d['confirm'])) {
                $entry['confirm'] = true;
            }
            if ($entry !== []) {
                $silos[$silo] = $entry;
            }
        }

        return ['spokes' => $spokes, 'silos' => $silos];
    }

    /**
     * @return list<PruneRow>
     */
    private function decidableRows(): array
    {
        $site = $this->site();

        return $site === null ? [] : $this->engine()->plan($site)->decidable();
    }

    private function site(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    private function engine(): PruneEngine
    {
        return app(PruneEngine::class);
    }
}
