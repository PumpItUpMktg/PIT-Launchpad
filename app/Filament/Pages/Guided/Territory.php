<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use Filament\Notifications\Notification;

/**
 * Step 2 · Territory. Wraps the existing locations layer (county select + 4-tier towns +
 * page_selected) inside the stepper + gating. (Locations wiring lands in the next layer.)
 */
class Territory extends GuidedPage
{
    protected static ?string $slug = 'setup/territory';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Territory';

    protected string $view = 'filament.guided.territory';

    public function step(): SetupStep
    {
        return SetupStep::Territory;
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Territory);

        Notification::make()->title('Territory saved.')->success()->send();
        $this->redirect(SetupStep::Structure->pageClass()::getUrl());
    }
}
