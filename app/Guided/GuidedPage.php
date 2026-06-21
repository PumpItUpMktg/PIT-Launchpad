<?php

namespace App\Guided;

use App\Enums\SetupStep;
use App\Models\SetupState;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Base for the guided-setup Filament pages (operator `admin` panel). Each concrete page is one
 * step; this base resolves the working site, loads its {@see SetupState}, and runs the **mount
 * gate** — a request for a step beyond the furthest-unlocked redirects back to the current step
 * ({@see StepGate}). It also feeds the shared lp- stepper rail (done / active / locked + Grow).
 *
 * House pattern: lp- inline styles in the blade, no theme build.
 *
 * @property-read array<int, array{step: SetupStep, active: bool, done: bool, locked: bool, url: string|null}> $steps
 * @property-read array<string, string> $siteOptions
 */
abstract class GuidedPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string|\UnitEnum|null $navigationGroup = 'Guided Setup';

    public ?string $siteId = null;

    /** The step this page represents. */
    abstract public function step(): SetupStep;

    public function mount(): void
    {
        $site = $this->resolveSite();
        if ($site === null) {
            return; // no sites yet — the page renders its empty state
        }

        $gate = app(StepGate::class);
        $state = $gate->state($site);
        $target = $gate->resolve($state, $this->step());

        if ($target !== $this->step()) {
            $this->redirect($target->pageClass()::getUrl());
        }
    }

    /** Switch the working site (session-persisted) and re-enter the flow at its current step. */
    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
            $state = app(StepGate::class)->state(Site::query()->findOrFail($siteId));
            $this->redirect($state->step()->pageClass()::getUrl());
        }
    }

    public function getTitle(): string
    {
        return $this->step()->label();
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    public function getStateProperty(): ?SetupState
    {
        $site = $this->getSite();

        return $site === null ? null : app(StepGate::class)->state($site);
    }

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /**
     * The lp- rail model: every step with its done / active / locked status + a URL when reachable.
     *
     * @return array<int, array{step: SetupStep, active: bool, done: bool, locked: bool, url: string|null}>
     */
    public function getStepsProperty(): array
    {
        $state = $this->getStateProperty();
        $gate = app(StepGate::class);

        $rows = [];
        // The rail is the 7 setup steps + Grow. "Build" is no longer a stage (materialize replaced
        // the build phase; pages build on demand from Grow).
        foreach ([...SetupStep::setupSteps(), SetupStep::Grow] as $step) {
            $unlocked = $state !== null && $gate->isUnlocked($state, $step);
            $rows[] = [
                'step' => $step,
                'active' => $step === $this->step(),
                'done' => $state !== null && $state->isComplete($step),
                'locked' => ! $unlocked,
                'url' => $unlocked ? $step->pageClass()::getUrl() : null,
            ];
        }

        return $rows;
    }

    private function resolveSite(): ?Site
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }

        return $site;
    }
}
