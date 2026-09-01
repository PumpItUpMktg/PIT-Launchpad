<?php

namespace App\Filament\Client\Pages;

use App\Client\ClientAccess;
use App\Client\ClientContext;
use App\Client\MonthlyKeywordReport;
use App\Client\MonthlyReportPdf;
use App\Models\Site;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The §7c client monthly keyword-improvement report (in-panel face). White-labeled, read-only:
 * month-over-month organic movement + Search Console reach for the client's site, with a month
 * selector and the standard §7c site switcher. Account-scoped through {@see ClientContext}; thin
 * over {@see MonthlyKeywordReport} — no engine logic here. The PDF/email face shares the same
 * view-model.
 *
 * @property-read array<string, mixed> $report
 * @property-read array<string, string> $siteOptions
 * @property-read array<string, string> $monthOptions
 */
class MonthlyReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Monthly Report';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.client.pages.monthly-report';

    public ?string $siteId = null;

    public ?string $monthKey = null;

    public function mount(): void
    {
        $this->siteId = app(ClientContext::class)->site()?->id;
        $this->monthKey = Carbon::now()->format('Y-m');
    }

    public function updatedSiteId(): void
    {
        // Mirror the §7c switcher: the session selection drives ClientContext everywhere.
        session(['client_site_id' => $this->siteId]);
    }

    public function getTitle(): string
    {
        return 'Your Monthly Report';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): ?StreamedResponse => $this->downloadPdf()),
        ];
    }

    private function downloadPdf(): ?StreamedResponse
    {
        $site = $this->site();
        if ($site === null) {
            return null;
        }

        $month = $this->monthKey !== null
            ? Carbon::createFromFormat('!Y-m', $this->monthKey)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $pdf = app(MonthlyReportPdf::class);

        return response()->streamDownload(
            fn () => print ($pdf->for($site, $month)->output()),
            $pdf->filename($site, $month),
            ['Content-Type' => 'application/pdf'],
        );
    }

    private function site(): ?Site
    {
        $user = app(ClientContext::class)->user();

        return $user !== null ? app(ClientAccess::class)->currentSite($user, $this->siteId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReportProperty(): array
    {
        $site = $this->site();
        if ($site === null) {
            return [];
        }

        $month = $this->monthKey !== null
            ? Carbon::createFromFormat('!Y-m', $this->monthKey)->startOfMonth()
            : Carbon::now()->startOfMonth();

        return app(MonthlyKeywordReport::class)->for($site, $month);
    }

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        $user = app(ClientContext::class)->user();
        if ($user === null) {
            return [];
        }

        return app(ClientAccess::class)->sites($user)->pluck('brand_name', 'id')->all();
    }

    /**
     * The last six months, newest first (value 'Y-m' → label 'F Y').
     *
     * @return array<string, string>
     */
    public function getMonthOptionsProperty(): array
    {
        $options = [];
        $cursor = Carbon::now()->startOfMonth();
        for ($i = 0; $i < 6; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor = $cursor->subMonthNoOverflow();
        }

        return $options;
    }
}
