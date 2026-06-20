<?php

namespace App\Filament\Pages\Guided;

use App\Build\InventoryPlan;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;

/**
 * The Page Inventory bridge — what "blueprint confirmed" resolves to, between Structure-finalize
 * and Approve. Shows the concrete page list the directed-coverage blueprint produces (Service +
 * Location, via {@see InventoryPlan}) so the user sees exactly what generation will build before
 * approving. Presentation only; Continue → Approve, where the standard-page toggles finish the
 * full build manifest. Shares the Approve gate slot ({@see step()}) — reachable once the
 * structure is finalized.
 *
 * @property-read array<string, mixed> $inventory
 */
class Inventory extends GuidedPage
{
    protected static ?string $slug = 'setup/inventory';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Page inventory';

    protected string $view = 'filament.guided.inventory';

    /** Shares Approve's gate slot — the inventory is the front door of Approve. */
    public function step(): SetupStep
    {
        return SetupStep::Approve;
    }

    public function getTitle(): string
    {
        return 'Page inventory';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInventoryProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['counts' => ['total' => 0, 'service' => 0, 'location_now' => 0, 'reserve' => 0], 'silos' => [], 'tiers' => []]
            : app(InventoryPlan::class)->for($site);
    }

    public function proceed(): void
    {
        $this->redirect(SetupStep::Approve->pageClass()::getUrl());
    }
}
