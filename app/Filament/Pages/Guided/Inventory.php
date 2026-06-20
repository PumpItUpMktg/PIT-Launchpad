<?php

namespace App\Filament\Pages\Guided;

use App\Build\InventoryPlan;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;

/**
 * Step 4 · Page inventory — the operator-detailed, read-only "what gets built" review between
 * Structure and Approve. Renders the directed-coverage blueprint as a concrete page list
 * ({@see InventoryPlan}): the Foundation (standard) layer kept separate from the service-by-silo
 * and location-by-tier directed coverage. No toggles here — accept/decline stays at Approve.
 * Continue advances to Approve. Shares Approve's prerequisite (no new gate), so it's a
 * pass-through review reachable once the structure is finalized.
 *
 * @property-read array<string, mixed> $inventory
 */
class Inventory extends GuidedPage
{
    protected static ?string $slug = 'setup/inventory';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Page inventory';

    protected string $view = 'filament.guided.inventory';

    public function step(): SetupStep
    {
        return SetupStep::Inventory;
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

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site !== null) {
            $gate = app(StepGate::class);
            $gate->complete($gate->state($site), SetupStep::Inventory); // pass-through: advance current_step
        }

        $this->redirect(SetupStep::Approve->pageClass()::getUrl());
    }
}
