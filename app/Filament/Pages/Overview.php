<?php

namespace App\Filament\Pages;

use App\Enums\SetupStep;
use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource\Pages\CreateSite;
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
        $states = SetupState::query()->pluck('current_step', 'site_id');
        $stepCount = count(SetupStep::setupSteps());

        $cards = [];
        foreach (Site::query()->orderBy('brand_name')->get() as $site) {
            $onboarding = $site->status === SiteStatus::Onboarding;
            $stats = $metrics->statCards($site->id);
            $step = (int) ($states[$site->id] ?? 1);

            $cards[] = [
                'id' => $site->id,
                'name' => $site->brand_name,
                'status' => $site->status->value,
                'onboarding' => $onboarding,
                'pct' => $onboarding ? min(100, (int) round(min($step, $stepCount) / $stepCount * 100)) : 100,
                'url' => $onboarding
                    ? (SetupStep::tryFrom($step) ?? SetupStep::Business)->pageClass()::getUrl(['site' => $site->id])
                    : SiteCockpit::getUrl(['site' => $site->id]),
                'signals' => [
                    'publish' => $stats['approved_pending'],
                    'review' => $stats['needs_review'],
                    'failed' => $stats['render_failed'] + $stats['publish_failed'],
                ],
            ];
        }

        return $cards;
    }
}
