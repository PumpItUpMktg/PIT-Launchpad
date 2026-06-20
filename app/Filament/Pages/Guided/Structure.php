<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Filament\Pages\SiloPrune;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Interview\Arrange\FlagResolver;
use App\Interview\Prune\PruneEngine;
use App\Interview\Prune\PruneRow;
use App\Jobs\BuildStructure;
use App\Models\ArrangementFlag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Filament\Notifications\Notification;

/**
 * Step 3 · Structure (operator detail surface). On first entry the engine chain
 * ({@see BuildStructure}: silo-gen → silo-volume → auto-arrange) builds the structure with a
 * "building" progress state; when ready it renders the arranged tree + the auto-arrange flag
 * cards. **Finalize is blocked while any flag is unresolved** (§4b). Finalize commits the
 * arranged tree through the proven {@see PruneEngine} (empty decision-set = keep everything
 * arranged, the stated-service floor) and advances. The full prune editing surface stays at
 * {@see SiloPrune} (linked).
 *
 * @property-read array<string, list<PruneRow>> $bySilo
 * @property-read array<int, array{id: string, type: string, message: string, score: float|null, spoke: string}> $arrangeFlags
 */
class Structure extends GuidedPage
{
    protected static ?string $slug = 'setup/structure';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Structure';

    protected string $view = 'filament.guided.structure';

    public function step(): SetupStep
    {
        return SetupStep::Structure;
    }

    public function mount(): void
    {
        parent::mount(); // resolve site + gate

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $state = app(StepGate::class)->state($site);
        $hasSpokes = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->exists();

        if ($hasSpokes) {
            if ($state->structure_status !== 'ready') {
                $state->update(['structure_status' => 'ready']);
            }

            return;
        }

        // No structure yet — kick the build chain if Step 1 left us a seed.
        if ($state->structure_status === null && $this->hasSeed($site)) {
            $state->update(['structure_status' => 'building']);
            BuildStructure::dispatch($site->id);
        }
    }

    public function getStatusProperty(): ?string
    {
        $site = $this->getSite();

        return $site === null ? null : app(StepGate::class)->state($site)->structure_status;
    }

    /** Retry a failed / re-kick the build. */
    public function rebuild(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        app(StepGate::class)->state($site)->update(['structure_status' => 'building']);
        BuildStructure::dispatch($site->id);
    }

    /**
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

    public function getCanFinalizeProperty(): bool
    {
        return $this->getStatusProperty() === 'ready' && $this->unresolvedFlags() === 0;
    }

    public function finalize(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if ($this->getStatusProperty() !== 'ready') {
            Notification::make()->title('Still building your structure…')->warning()->send();

            return;
        }

        $count = $this->unresolvedFlags();
        if ($count > 0) {
            Notification::make()
                ->title('Resolve the flagged items first.')
                ->body($count.' item(s) still need your input.')
                ->warning()
                ->send();

            return;
        }

        // Empty decision-set = keep everything as arranged (the stated-service floor) + confirm.
        app(PruneEngine::class)->finalize($site, []);

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Structure);

        Notification::make()->title('Structure finalized.')->success()->send();
        $this->redirect(SetupStep::Approve->pageClass()::getUrl());
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
