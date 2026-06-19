<?php

namespace App\Filament\Client\Pages;

use App\Client\ClientAccess;
use App\Client\ClientContext;
use App\Client\PagePlan as PagePlanView;
use App\Client\PlanApproval;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * The §7c client page-plan surface (auto-arrange increment 5). White-labeled, read-only
 * presentation of the arranged page inventory + lead-upside the engine produced for the
 * client's site, with one action: sign off on the plan. Account-scoped through
 * {@see ClientContext}; thin over {@see PagePlanView} (the inventory) and {@see PlanApproval}
 * (the sign-off) — no engine logic here.
 *
 * @property-read array<string, mixed> $plan
 * @property-read array{approved: bool, at: Carbon|null} $approval
 * @property-read array<string, string> $siteOptions
 */
class PagePlan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Page Plan';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.client.pages.page-plan';

    public ?string $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(ClientContext::class)->site()?->id;
    }

    public function updatedSiteId(): void
    {
        // Mirror the §7c switcher: the session selection drives ClientContext everywhere.
        session(['client_site_id' => $this->siteId]);
    }

    public function getTitle(): string
    {
        return 'Your Page Plan';
    }

    private function site(): ?Site
    {
        $user = app(ClientContext::class)->user();

        return $user !== null ? app(ClientAccess::class)->currentSite($user, $this->siteId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlanProperty(): array
    {
        $site = $this->site();

        return $site !== null
            ? app(PagePlanView::class)->for($site)
            : ['silos' => [], 'totals' => ['silos' => 0, 'pages' => 0, 'sections' => 0, 'volume' => 0]];
    }

    /**
     * @return array{approved: bool, at: Carbon|null}
     */
    public function getApprovalProperty(): array
    {
        $site = $this->site();

        return $site !== null
            ? app(PlanApproval::class)->status($site)
            : ['approved' => false, 'at' => null];
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

    public function approve(): void
    {
        $user = app(ClientContext::class)->user();
        $site = $this->site();

        if ($user === null || $site === null) {
            Notification::make()->title('No plan to approve yet.')->warning()->send();

            return;
        }

        if (app(PlanApproval::class)->approve($site, $user)) {
            Notification::make()->title('Plan approved — thank you!')->success()->send();

            return;
        }

        Notification::make()->title('Your plan is still being prepared.')->warning()->send();
    }
}
