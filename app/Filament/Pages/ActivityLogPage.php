<?php

namespace App\Filament\Pages;

use App\Activity\ActivityLog;
use App\Activity\ActivityPeriod;
use App\Activity\MonthlySnapshots;
use App\Models\Site;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Activity (§ Activity): the operator's one-page history of what the system completed for the working
 * tenant — a headline strip of the work done in the period, a dated timeline of plain sentences, and the
 * metric movement start-to-end — plus the Progress tab: every closed month, frozen, with the open month
 * live. Read-only; thin over {@see ActivityLog} and {@see MonthlySnapshots}. Printable.
 *
 * @property-read array<string, mixed>|null $report
 * @property-read list<array<string, mixed>> $progress
 * @property-read array<string, string> $periodOptions
 */
class ActivityLogPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $title = 'Activity';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'activity';

    protected string $view = 'filament.pages.activity-log';

    public ?string $siteId = null;

    /** '30d' | '90d' | 'Y-m' */
    #[Url]
    public string $period = '30d';

    /** 'log' | 'progress' */
    #[Url]
    public string $tab = 'log';

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canOperate() ?? false;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'progress' ? 'progress' : 'log';
    }

    public function getReportProperty(): ?array
    {
        $site = $this->site();

        return $site === null ? null : app(ActivityLog::class)->for($site, ActivityPeriod::fromKey($this->period));
    }

    public function getProgressProperty(): array
    {
        $site = $this->site();

        return $site === null ? [] : app(MonthlySnapshots::class)->progress($site);
    }

    /** Last 30 / 90 days, then the last twelve months. @return array<string, string> */
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

    private function site(): ?Site
    {
        return $this->siteId !== null ? Site::withoutGlobalScopes()->find($this->siteId) : null;
    }
}
