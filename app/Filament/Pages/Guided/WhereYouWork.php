<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Locations\Concerns\ManagesLocationCoverage;
use Filament\Notifications\Notification;

/**
 * Step 4 · Where you work (setup-redesign relay: Territory + the Locations form merged into one
 * step). Location leads and its territory nests beneath it: each physical location is a block —
 * where it is, the counties it serves, the tiered towns with page selection — while the single
 * coverage map at the top stays one picture of everywhere the business works across all
 * locations. The whole workspace is {@see ManagesLocationCoverage}, shared verbatim with the
 * Settings Locations page (the post-setup editor). Continue → territory_done.
 */
class WhereYouWork extends GuidedPage
{
    use ManagesLocationCoverage;

    protected static ?string $slug = 'setup/where-you-work';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Where you work';

    protected string $view = 'filament.guided.where-you-work';

    public function step(): SetupStep
    {
        return SetupStep::WhereYouWork;
    }

    public function mount(): void
    {
        parent::mount(); // resolve the working site into $siteId

        if ($this->getSite() !== null) {
            $this->enterCoverageWorkspace();
        }
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::WhereYouWork);

        Notification::make()->title('Service area saved.')->success()->send();
        $this->redirect(SetupStep::Plan->pageClass()::getUrl());
    }
}
