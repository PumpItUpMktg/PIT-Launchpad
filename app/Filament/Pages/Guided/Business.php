<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use Filament\Notifications\Notification;

/**
 * Step 1 · Business & services. Brand/trade + stated services, with the connecting-services
 * suggester. (Intake + suggester UI lands in the next layer; the spine wires completion.)
 */
class Business extends GuidedPage
{
    protected static ?string $slug = 'setup';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Business & services';

    protected string $view = 'filament.guided.business';

    public function step(): SetupStep
    {
        return SetupStep::Business;
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Business);

        Notification::make()->title('Services saved.')->success()->send();
        $this->redirect(SetupStep::Territory->pageClass()::getUrl());
    }
}
