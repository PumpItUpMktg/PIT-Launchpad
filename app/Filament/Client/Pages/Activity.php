<?php

namespace App\Filament\Client\Pages;

use App\Activity\ActivityLog;
use App\Activity\ActivityPeriod;
use App\Activity\MonthlySnapshots;
use App\Client\ClientAccess;
use App\Client\ClientContext;
use App\Models\Site;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The client's Activity page (§ Activity): the same one-page history and monthly progress the operator
 * reads, with the operator-only entries filtered out — what was completed for their site and how the
 * numbers moved. White-labeled, read-only, account-scoped like the rest of the portal.
 *
 * @property-read array<string, mixed>|null $report
 * @property-read list<array<string, mixed>> $progress
 * @property-read array<string, string> $periodOptions
 * @property-read array<string, string> $siteOptions
 */
class Activity extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Activity';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.client.pages.activity';

    public ?string $siteId = null;

    #[Url]
    public string $period = '30d';

    #[Url]
    public string $tab = 'log';

    public function mount(): void
    {
        $this->siteId = app(ClientContext::class)->site()?->id;
    }

    public function updatedSiteId(): void
    {
        session(['client_site_id' => $this->siteId]);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'progress' ? 'progress' : 'log';
    }

    public function getTitle(): string
    {
        return 'Activity';
    }

    public function getReportProperty(): ?array
    {
        $site = $this->site();

        return $site === null ? null : app(ActivityLog::class)->for($site, ActivityPeriod::fromKey($this->period), clientView: true);
    }

    public function getProgressProperty(): array
    {
        $site = $this->site();

        return $site === null ? [] : app(MonthlySnapshots::class)->progress($site);
    }

    /** @return array<string, string> */
    public function getPeriodOptionsProperty(): array
    {
        $options = ['30d' => 'Last 30 days', '90d' => 'Last 90 days'];
        $cursor = Carbon::now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor = $cursor->subMonthNoOverflow();
        }

        return $options;
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        $user = app(ClientContext::class)->user();

        return $user === null ? [] : app(ClientAccess::class)->sites($user)->pluck('brand_name', 'id')->all();
    }

    /** Only a site the client owns. */
    private function site(): ?Site
    {
        if ($this->siteId === null) {
            return null;
        }
        $user = app(ClientContext::class)->user();
        if ($user === null) {
            return null;
        }

        return app(ClientAccess::class)->sites($user)->firstWhere('id', $this->siteId);
    }
}
