<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use Filament\Notifications\Notification;

/**
 * Step 4 · Approve & build. The plain-language site plan (the simplified surface) + build
 * config; Approve & build triggers generation and goes live. (Plan render, config persistence,
 * and the generation trigger land in the next layer; the spine wires completion → launched.)
 */
class Approve extends GuidedPage
{
    protected static ?string $slug = 'setup/approve';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Approve & build';

    protected string $view = 'filament.guided.approve';

    public function step(): SetupStep
    {
        return SetupStep::Approve;
    }

    public function approveAndBuild(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        $state = $gate->complete($gate->state($site), SetupStep::Approve);
        $state->update(['launched' => true]);

        // (Generation trigger wires here in the next layer.)

        Notification::make()->title('Approved — your site is building.')->success()->send();
        $this->redirect(SetupStep::Grow->pageClass()::getUrl());
    }
}
