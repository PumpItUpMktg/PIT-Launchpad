<?php

namespace App\Filament\Pages\Guided;

use App\Client\PagePlan;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\SiteBuilder;
use App\Guided\StepGate;
use Filament\Notifications\Notification;

/**
 * Step 4 · Approve & build. The plain-language site plan (reusing the §7c {@see PagePlan}
 * view-model — categories, pages, "also covers"; no tags/volumes/silo vocabulary) + the build
 * config toggles. Approve & build persists the config, triggers generation ({@see SiteBuilder} —
 * stubbed until the generation entrypoint lands), and goes live.
 *
 * @property-read array<string, mixed> $plan
 */
class Approve extends GuidedPage
{
    protected static ?string $slug = 'setup/approve';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Approve & build';

    protected string $view = 'filament.guided.approve';

    public bool $localize = true;

    public int $townPagePace = 5;

    public bool $freshContent = true;

    public function step(): SetupStep
    {
        return SetupStep::Approve;
    }

    public function mount(): void
    {
        parent::mount();

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $state = app(StepGate::class)->state($site);
        $this->localize = $state->localize;
        $this->townPagePace = $state->town_page_pace;
        $this->freshContent = $state->fresh_content;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['silos' => [], 'totals' => ['silos' => 0, 'pages' => 0, 'sections' => 0, 'volume' => 0]]
            : app(PagePlan::class)->for($site);
    }

    public function toggleLocalize(): void
    {
        $this->localize = ! $this->localize;
    }

    public function toggleFreshContent(): void
    {
        $this->freshContent = ! $this->freshContent;
    }

    public function approveAndBuild(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        $state = $gate->state($site);
        $state->update([
            'localize' => $this->localize,
            'town_page_pace' => max(1, $this->townPagePace),
            'fresh_content' => $this->freshContent,
        ]);

        $gate->complete($state, SetupStep::Approve);
        $state->update(['launched' => true]);

        app(SiteBuilder::class)->build($site); // generation trigger (stub until it lands)

        Notification::make()->title('Approved — your site is building.')->success()->send();
        $this->redirect(SetupStep::Grow->pageClass()::getUrl());
    }
}
