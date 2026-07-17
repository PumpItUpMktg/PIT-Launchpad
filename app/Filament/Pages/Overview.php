<?php

namespace App\Filament\Pages;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SetupStep;
use App\Enums\SiteStatus;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Site;
use App\Operator\PipelineMetrics;
use BackedEnum;
use Filament\Pages\Page;

/**
 * The admin landing — a triage board, not a site list. Two quarantined groups: "In setup"
 * (onboarding = setup tasks, resume the wizard at its step) on top, live sites (content tasks,
 * open Grow) below. Attention floats up — failures, then work-waiting, then stalled onboarding;
 * caught-up and untouched sink. "New site" is the single on-ramp into the wizard.
 *
 * @property-read list<array<string, mixed>> $sites
 */
class Overview extends Page
{
    /** A setup site with no wizard movement in this many days reads as "stalled". */
    private const STALLED_DAYS = 7;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?int $navigationSort = -10;

    /** Menu-map family tag: superseded — Operate's Dashboard + Portfolio carry the landing. */
    public static function menuTag(): string
    {
        return 'operate';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_operate_enabled');
    }

    protected static ?string $slug = '/'; // the panel landing

    protected string $view = 'filament.pages.overview';

    public function getTitle(): string
    {
        return 'Overview';
    }

    public function newSiteUrl(): string
    {
        return CreateSite::getUrl();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSitesProperty(): array
    {
        $metrics = app(PipelineMetrics::class);
        $states = SetupState::query()->get()->keyBy('site_id');
        $stepCount = count(SetupStep::setupSteps());

        $cards = [];
        foreach (Site::query()->orderBy('brand_name')->get() as $site) {
            $cards[] = $this->siteCard($site, $states->get($site->id), $metrics, $stepCount);
        }

        return collect($cards)->sortBy([
            ['sort', 'asc'],
            ['name', 'asc'],
        ])->values()->all();
    }

    /**
     * One triage card for a site — adaptive by mode (setup task vs content task).
     *
     * @return array<string, mixed>
     */
    private function siteCard(Site $site, ?SetupState $state, PipelineMetrics $metrics, int $stepCount): array
    {
        // A launched site is past the wizard even if its status somehow lagged — never resume the
        // wizard for it. The Finalize-Plan→Grow handoff flips status to Active; this is belt-and-
        // suspenders against a stuck status.
        $onboarding = $site->status === SiteStatus::Onboarding && ! (bool) $state?->launched;
        $stats = $metrics->statCards($site->id);
        $step = $state !== null ? $state->current_step : 1;
        $complete = (bool) $state?->onboardingComplete();
        $signals = [
            'publish' => $stats['approved_pending'],
            'review' => $stats['needs_review'],
            'failed' => $stats['render_failed'] + $stats['publish_failed'],
        ];
        $stalled = $onboarding && $state !== null && $state->updated_at !== null
            && $state->updated_at->lt(now()->subDays(self::STALLED_DAYS));

        return [
            'id' => $site->id,
            'name' => $site->brand_name,
            'status' => $site->status->value,
            'onboarding' => $onboarding,
            // Two quarantined modes: a setup card is a *setup task*, a live card is a *content task*.
            'mode' => $onboarding ? 'setup' : 'live',
            'pct' => $onboarding
                ? ($complete ? 100 : min(100, (int) round(min($step, $stepCount) / $stepCount * 100)))
                : 100,
            // Setup card: resume exactly where they stopped. Live card: opens Grow (the active-site
            // home — pages list, proof step, content pipeline). Cockpit reconciled to Grow.
            'url' => $onboarding
                ? (SetupStep::tryFrom($step) ?? SetupStep::Business)->pageClass()::getUrl(['site' => $site->id])
                : Grow::getUrl(['site' => $site->id]),
            'resume' => $onboarding
                ? 'Step '.min($step, $stepCount)." of {$stepCount} · ".(SetupStep::tryFrom($step) ?? SetupStep::Business)->label()
                : null,
            'stalled' => $stalled,
            // The work waiting on the operator — the live card's whole reason to exist.
            'work' => $onboarding ? null : $this->workSummary($signals),
            // Build progress — "N of M pages live"; "building" is a metric, not a stage.
            'pages' => $this->buildProgress($site),
            'signals' => $signals,
            // Triage rank — attention floats up (failures → work-waiting → stalled → calm).
            'sort' => $this->attentionRank($onboarding, $stalled, $signals),
        ];
    }

    /**
     * Triage rank — lower floats up. Failures first, then live work-waiting, then stalled
     * onboarding, then calm live, then fresh/untouched onboarding sinks to the bottom.
     *
     * @param  array{publish: int, review: int, failed: int}  $signals
     */
    private function attentionRank(bool $onboarding, bool $stalled, array $signals): int
    {
        return match (true) {
            ! $onboarding && $signals['failed'] > 0 => 0,                              // ⚠ something broke
            ! $onboarding && ($signals['review'] + $signals['publish']) > 0 => 1,      // work waiting
            $onboarding && $stalled => 2,                                              // stuck mid-setup
            ! $onboarding => 3,                                                        // caught up / live
            default => 4,                                                              // fresh onboarding
        };
    }

    /**
     * The human work-waiting line for a live card.
     *
     * @param  array{publish: int, review: int, failed: int}  $signals
     */
    private function workSummary(array $signals): string
    {
        if ($signals['failed'] > 0) {
            return "⚠ {$signals['failed']} need attention";
        }

        $parts = [];
        if ($signals['review'] > 0) {
            $parts[] = "{$signals['review']} to review";
        }
        if ($signals['publish'] > 0) {
            $parts[] = "{$signals['publish']} to publish";
        }

        return $parts === [] ? 'All caught up' : implode(' · ', $parts);
    }

    /**
     * Build progress for a site — published pages over total materialized pages. "Building" is a
     * metric, not a lifecycle stage.
     *
     * @return array{published: int, total: int}
     */
    private function buildProgress(Site $site): array
    {
        $base = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value);

        return [
            'published' => (clone $base)->where('status', ContentStatus::Published->value)->count(),
            'total' => (clone $base)->count(),
        ];
    }
}
