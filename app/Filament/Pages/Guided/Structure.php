<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\ArrangementFlag;
use Filament\Notifications\Notification;

/**
 * Step 3 · Structure (operator detail surface). On entry the engine chain (silo-gen →
 * silo-volume → auto-arrange) builds the structure; the operator resolves the auto-arrange
 * flags, then finalizes. **Finalize is blocked while any auto-arrange flag is unresolved** —
 * the governance rule locked in §4b. (Engine-on-entry job + the prune detail embed land in the
 * next layer; the spine wires the finalize flag-gate + completion.)
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

    /** Unresolved auto-arrange flags block Finalize (§4b). */
    public function getUnresolvedFlagCountProperty(): int
    {
        return $this->unresolvedFlags();
    }

    public function getCanFinalizeProperty(): bool
    {
        return $this->unresolvedFlags() === 0;
    }

    private function unresolvedFlags(): int
    {
        $site = $this->getSite();

        return $site === null ? 0 : ArrangementFlag::query()->where('site_id', $site->id)->count();
    }

    public function finalize(): void
    {
        $site = $this->getSite();
        if ($site === null) {
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

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Structure);

        Notification::make()->title('Structure finalized.')->success()->send();
        $this->redirect(SetupStep::Approve->pageClass()::getUrl());
    }
}
