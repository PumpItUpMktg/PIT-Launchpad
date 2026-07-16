<?php

namespace App\Filament\Pages\Gathering;

use App\Build\ApproveAndBuild;
use App\Enums\StandardPageType;
use App\Gathering\LaunchReadiness;
use App\Guided\StepGate;
use App\Locations\LocalRelevance;
use App\Standard\StandardPages;
use Filament\Notifications\Notification;

/**
 * New Setup · Step 9 — Launch: the readiness checklist + the build trigger. Closes the new
 * Setup's arc (1–7 gather → 8 generate → 9 launch) with the guided Plan's Approve machinery,
 * run through the shared {@see ApproveAndBuild} core: standard-pages curation, build config
 * (localize / town-page pace / fresh content), manifest assembly + page materialization, the
 * WP category sync, and the wizard-complete flip (site Onboarding → Active). The checklist is
 * red-until-green over every gather/generate output; only structure + flags + services hard-gate
 * (the same bar Plan enforced) — the rest deep-link their fixing surface.
 *
 * @property-read list<array{key: string, label: string, ok: bool, required: bool, detail: string, url: string|null, launch_ok: bool}> $checklist
 * @property-read bool $canLaunch
 * @property-read bool $launched
 * @property-read list<array{type: StandardPageType, accepted: bool}> $standardPages
 */
class LaunchStep extends GatheringPage
{
    protected static ?string $slug = 'setup2/launch';

    protected static ?string $navigationLabel = 'Launch';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.gathering.launch-step';

    // Build config (persisted through ApproveAndBuild at launch).
    public bool $localize = true;

    public int $townPagePace = 5;

    public bool $freshContent = true;

    protected function afterSiteResolved(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $state = app(StepGate::class)->state($site);

        // First visit: default the offerable optional standard pages ON (curate by deselecting) —
        // same seeding the guided Plan does; a later operator choice is never overridden.
        if ($state->standard_pages === null) {
            $standard = app(StandardPages::class);
            foreach ($standard->offerable($site) as $row) {
                $standard->setAccepted($site, $row['type'], true);
            }
        }

        // Seed the population-based town selection so build-now vs reserve is real at materialize.
        app(LocalRelevance::class)->seedInitialSelection($site);

        $this->localize = $state->localize ?? true;
        $this->townPagePace = $state->town_page_pace ?? 5;
        $this->freshContent = $state->fresh_content ?? true;
    }

    /** @return list<array{key: string, label: string, ok: bool, required: bool, detail: string, url: string|null, launch_ok: bool}> */
    public function getChecklistProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(LaunchReadiness::class)->checklist($site);
    }

    public function getCanLaunchProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(LaunchReadiness::class)->canLaunch($site);
    }

    public function getLaunchedProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && (bool) app(StepGate::class)->state($site)->launched;
    }

    /** @return list<array{type: StandardPageType, accepted: bool}> */
    public function getStandardPagesProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(StandardPages::class)->offerable($site);
    }

    /** Toggle an optional standard page into/out of the build manifest. */
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
     * Launch (or re-run) the build: the shared approve→build core — implicit structure finalize,
     * build config, manifest, page materialization, category sync, Onboarding → Active.
     * Idempotent, so a re-launch after a business change just re-materializes.
     */
    public function launch(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (! $this->getCanLaunchProperty()) {
            $blocking = collect($this->getChecklistProperty())
                ->filter(fn (array $i) => $i['required'] && ! $i['launch_ok'])
                ->pluck('label')
                ->join(', ');
            Notification::make()->warning()
                ->title('Not ready to launch')
                ->body("Resolve first: {$blocking}.")
                ->send();

            return;
        }

        app(ApproveAndBuild::class)->approve($site, $this->localize, max(1, $this->townPagePace), $this->freshContent);

        Notification::make()->success()
            ->title($this->getLaunchedProperty() ? 'Build complete' : 'Launched')
            ->body('Pages are materialized — generate and publish them from the Operate boards.')
            ->send();
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return ['state' => 'empty', 'label' => 'Empty'];
        }

        if ($this->getLaunchedProperty()) {
            return ['state' => 'complete', 'label' => 'Launched'];
        }

        return $this->getCanLaunchProperty()
            ? ['state' => 'attention', 'label' => 'Ready to launch']
            : ['state' => 'attention', 'label' => 'Blocked — see the checklist'];
    }
}
