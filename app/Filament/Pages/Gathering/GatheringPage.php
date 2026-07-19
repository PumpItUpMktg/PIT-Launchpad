<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\ProvenanceState;
use App\Gathering\Provenance;
use App\Gathering\SetupProgress;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the NEW Setup group (gathering relay — parallel build). The whole group is gated by
 * `launchpad.new_setup_enabled`: flag off ⇒ the admin is identical to before (nothing registers);
 * flag on ⇒ six steps appear alongside the existing menu under /admin/setup2/*. New UI over the
 * SAME models — no parallel data store; concurrent edits via old surfaces are fine
 * (last-write-wins). No gates between steps: every step always opens; readiness is state
 * (the chip), never a wall.
 */
abstract class GatheringPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    public ?string $siteId = null;

    // Nav-final: the nine step pages leave the sidebar — a single top-level "Setup" entry
    // ({@see SetupEntry}) is the only registered item; the in-page rail + Next buttons carry
    // the walk-through and /admin/setup2 resumes at the first unfinished step. The steps stay
    // fully routable (deep links, rail, resume) — just out of the sidebar.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * The stepper rail — all nine steps with done-state, this page marked current.
     *
     * @return list<array{n: int, class: class-string, label: string, url: string, done: bool, optional: bool, current: bool}>
     */
    public function getStepsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(SetupProgress::class)->steps($site, static::class);
    }

    /** The step after this one (null on the last step). @return array{label: string, url: string}|null */
    public function nextStep(): ?array
    {
        $steps = $this->getStepsProperty();
        foreach ($steps as $i => $step) {
            if ($step['current'] && isset($steps[$i + 1])) {
                return ['label' => $steps[$i + 1]['label'], 'url' => $steps[$i + 1]['url']];
            }
        }

        return null;
    }

    /** Whether Continue runs this page's save first (drives the button label). */
    public function savesOnContinue(): bool
    {
        return false;
    }

    /** Hook: pages with a primary save persist before moving on. */
    protected function beforeContinue(): void {}

    /** The footer's "Save & continue / Next" — persist (when the page saves) and advance. */
    public function continueToNext(): void
    {
        $this->beforeContinue();

        $next = $this->nextStep();
        if ($next !== null) {
            $this->redirect($next['url'], navigate: true);
        }
    }

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }

        $this->afterSiteResolved();
    }

    /** Hook for steps that load state once the working site is known. */
    protected function afterSiteResolved(): void {}

    /** Switch the working site (session-persisted, shared with Grow/Live/Targeting). */
    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
            $this->afterSiteResolved();
        }
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /**
     * The step's readiness chip — computed from its records, same pattern as location-page
     * readiness. Never blocks anything.
     *
     * @return array{state: 'complete'|'attention'|'empty', label: string}
     */
    abstract public function readiness(): array;

    /**
     * The seeded/confirmed map for a record — review surfaces render their "from interview"
     * chips from this.
     *
     * @return array<string, ProvenanceState>
     */
    public function provenanceFor(Model $model): array
    {
        return app(Provenance::class)->forModel($model);
    }

    /**
     * The review-surface save contract: flip every SEEDED field the operator just saved to
     * confirmed. Fields with no provenance row stay rowless (manual entry is normal data).
     *
     * @param  list<string>  $fields
     */
    protected function confirmSeeded(Model $model, array $fields): void
    {
        $provenance = app(Provenance::class);
        $map = $provenance->forModel($model);
        foreach ($fields as $field) {
            if (isset($map[$field])) {
                $provenance->confirm($model, $field);
            }
        }
    }
}
