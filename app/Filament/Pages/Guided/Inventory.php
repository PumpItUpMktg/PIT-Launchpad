<?php

namespace App\Filament\Pages\Guided;

use App\Build\InventoryPlan;
use App\Enums\SetupStep;
use App\Enums\StandardPageType;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Locations\LocalRelevance;
use App\Standard\StandardPages;

/**
 * Step 4 · Page inventory — the operator-detailed "what gets built" review between Structure and
 * Approve. Renders the directed-coverage blueprint as a concrete page list ({@see InventoryPlan}):
 * the Foundation (standard) layer kept separate from the service-by-silo and location-by-tier
 * directed coverage. The optional foundation pages have working checkboxes that curate which
 * standard pages land in the build manifest ({@see StandardPages} accept set) — the selection is
 * what the build uses. Continue advances to Approve. Shares Approve's prerequisite (no new gate).
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

    public function mount(): void
    {
        parent::mount(); // resolve site + gate

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        // First visit: default the offerable optionals ON, so the inventory starts fully selected
        // (curate by deselecting). Respects any later operator choice (only seeds when untouched).
        $state = app(StepGate::class)->state($site);
        if ($state->standard_pages === null) {
            $standard = app(StandardPages::class);
            foreach ($standard->offerable($site) as $row) {
                $standard->setAccepted($site, $row['type'], true);
            }
        }

        // Seed the population-based town selection so the inventory's "build now vs. reserve"
        // split is real (the biggest towns build now; the rest drip live as they earn local
        // relevance). No-op once the operator has curated the pool.
        app(LocalRelevance::class)->seedInitialSelection($site);
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
