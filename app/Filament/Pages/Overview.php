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
 * The admin landing — a card per site, replacing the old pooled-across-tenants dashboard
 * (aggregate metrics aren't actionable). Each card shows status (onboarding with % through the
 * 7-step wizard, or live) + the live signals that tell the operator what to do (pages to publish,
 * needs review, failures), and clicks through: live → the per-site {@see SiteCockpit}; onboarding
 * → resume the wizard at its persisted step. "New site" is the single on-ramp into the wizard.
 *
 * @property-read list<array<string, mixed>> $sites
 */
class Overview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?int $navigationSort = -10;

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
            $state = $states->get($site->id);
            // A launched site is past the wizard even if its status somehow lagged — never resume
            // the wizard for it. The build→Grow handoff flips status to Active, so this is belt-and-
            // suspenders against a stuck status.
            $onboarding = $site->status === SiteStatus::Onboarding && ! (bool) $state?->launched;
            $stats = $metrics->statCards($site->id);
            $step = $state !== null ? $state->current_step : 1;
            // Progress reads completion across all 7 steps (Inventory now completes too).
            $complete = (bool) $state?->onboardingComplete();
            $pages = $this->buildProgress($site);

            $cards[] = [
                'id' => $site->id,
                'name' => $site->brand_name,
                'status' => $site->status->value,
                'onboarding' => $onboarding,
                'pct' => $onboarding
                    ? ($complete ? 100 : min(100, (int) round(min($step, $stepCount) / $stepCount * 100)))
                    : 100,
                // Onboarding → resume the wizard; Active → Grow (build pages on demand);
                // Live (client handover, §9) → the operator cockpit.
                'url' => match (true) {
                    $onboarding => (SetupStep::tryFrom($step) ?? SetupStep::Business)->pageClass()::getUrl(['site' => $site->id]),
                    $site->status === SiteStatus::Live => SiteCockpit::getUrl(['site' => $site->id]),
                    default => Grow::getUrl(['site' => $site->id]),
                },
                // Build progress — "building" is a metric, not a stage; an Active-but-empty site
                // reads as "Active · 0/N published", not a misleading "live with nothing on it".
                'pages' => $pages,
                'signals' => [
                    'publish' => $stats['approved_pending'],
                    'review' => $stats['needs_review'],
                    'failed' => $stats['render_failed'] + $stats['publish_failed'],
                ],
            ];
        }

        return $cards;
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
