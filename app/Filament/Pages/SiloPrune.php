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

    /** @var array<string, array{outcome?: string, tag?: string, granularity?: string}> keyed by spoke id */
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

        // Pre-fill each spoke with its current tag + the Phase-3 granularity recommendation
        // (both overridable); outcome starts empty (= undecided → not built).
        foreach ($this->engine()->plan($site)->rows as $row) {
            $this->spokeDecisions[$row->id] = [
                'outcome' => '',
                'tag' => $row->tag->value,
                'granularity' => $row->granularity->value,
            ];
        }

        // Resume any saved draft over the defaults.
        $draft = $this->engine()->loadDraft($site);
        $this->spokeDecisions = array_replace($this->spokeDecisions, is_array($draft['spokes'] ?? null) ? $draft['spokes'] : []);
        $this->siloDecisions = is_array($draft['silos'] ?? null) ? $draft['silos'] : [];

        $this->started = true;
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
     * Build-vs-drop preview from the current draft (the hard gate, transparent):
     * built = routed to a page, dropped = skipped or still undecided.
     *
     * @return array{built: int, skipped: int, pending: int}
     */
    public function getPreviewProperty(): array
    {
        $built = 0;
        $skipped = 0;
        $pending = 0;

        foreach ($this->decidableRows() as $row) {
            $outcome = $this->spokeDecisions[$row->id]['outcome'] ?? '';
            match ($outcome) {
                PruneOutcome::Offer->value, PruneOutcome::Future->value, PruneOutcome::Capture->value => $built++,
                PruneOutcome::Skip->value => $skipped++,
                default => $pending++,
            };
        }

        return ['built' => $built, 'skipped' => $skipped, 'pending' => $pending];
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
