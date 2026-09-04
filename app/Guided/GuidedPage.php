<?php

namespace App\Guided;

use App\Enums\SetupStep;
use App\Models\SetupState;
use App\Models\Site;
use App\Operator\ActiveTenant;
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
 */
abstract class GuidedPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static string|\UnitEnum|null $navigationGroup = 'Guided Setup';

    // The unified menu shows ONE "Setup" entry (SetupHome lands on the current step) — the step
    // pages themselves stay routable but leave the sidebar; the in-page rail is the step nav.
    protected static bool $shouldRegisterNavigation = false;

    public ?string $siteId = null;

    /** The step this page represents. */
    abstract public function step(): SetupStep;

    public function mount(): void
    {
        // Setup is a step process between tabs, not a gated wizard: every step is freely
        // reachable (each renders an honest empty state without its inputs). The StepGate still
        // TRACKS completion — the rail's done markers and SetupHome's landing read it — it just
        // no longer redirects. The working tenant is the locked ActiveTenant (chosen in the
        // Portfolio / topbar switcher), never a per-page selection.
        $this->siteId = app(ActiveTenant::class)->id();
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
            $rows[] = [
                'step' => $step,
                'active' => $step === $this->step(),
                'done' => $state !== null && $state->isComplete($step),
                // Free tabs: nothing locks. Done markers still show progress; the gate only tracks.
                'locked' => false,
                'url' => $step->pageClass()::getUrl(),
            ];
        }

        return $rows;
    }
}
