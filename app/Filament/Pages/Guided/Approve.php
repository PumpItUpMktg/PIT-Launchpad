<?php

namespace App\Filament\Pages\Guided;

use App\Build\BuildManifestAssembler;
use App\Enums\SetupStep;
use App\Enums\StandardPageType;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Locations\LocalRelevance;
use App\Standard\SitePlan;
use App\Standard\StandardPages;
use Filament\Notifications\Notification;

/**
 * Step 4 · Approve & build. The plain-language plan of the **complete site** ({@see SitePlan} —
 * fixed standard pages locked in, optional standard pages as data-gated accept/decline toggles,
 * service pages from the finalized structure, location pages) + the build config. Approve
 * assembles the build manifest across all three sources and hands off to the Build phase.
 *
 * @property-read array<string, mixed> $sitePlan
 * @property-read array{now: int, reserve: int, ready: int} $drip
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
    public function getSitePlanProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['fixed' => [], 'optionals' => [], 'service' => [], 'locations' => ['count' => 0, 'sample' => []]]
            : app(SitePlan::class)->for($site);
    }

    /**
     * The per-business location-page drip summary: how many towns build now vs. sit in reserve,
     * and how many reserve towns have already earned enough local relevance to drip live.
     *
     * @return array{now: int, reserve: int, ready: int}
     */
    public function getDripProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return ['now' => 0, 'reserve' => 0, 'ready' => 0];
        }

        $rows = collect(app(LocalRelevance::class)->forSite($site));
        $reserve = $rows->where('selected', false);

        return [
            'now' => $rows->where('selected', true)->count(),
            'reserve' => $reserve->count(),
            'ready' => $reserve->where('ready', true)->count(),
        ];
    }

    /** Accept/decline an optional standard page (offerable types only). */
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
            'build_status' => 'building',
        ]);

        $gate->complete($state, SetupStep::Approve); // → approved (Build sets launched)
        app(BuildManifestAssembler::class)->assemble($site);

        Notification::make()->title('Approved — assembling your build.')->success()->send();
        $this->redirect(SetupStep::Build->pageClass()::getUrl());
    }
}
