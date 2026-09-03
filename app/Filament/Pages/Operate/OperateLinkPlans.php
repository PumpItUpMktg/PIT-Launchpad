<?php

namespace App\Filament\Pages\Operate;

use App\Models\LinkPlan;
use App\Models\LinkPlanItem;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\LinkPlanActions;
use App\Publishing\Links\LinkPlanBuilder;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Operate · Link plans — the tiered-rollout link-plan board. Propose a five-source inbound-link plan for a
 * market's just-unlocked tier, review the proposed links, approve, and apply (write the links + submit
 * IndexNow). Nothing writes a live page until the operator applies. Thin over {@see LinkPlanBuilder} +
 * {@see LinkPlanActions}.
 *
 * @property-read Collection<int, LinkPlan> $plans
 * @property-read array<string, string> $siteOptions
 * @property-read array<string, string> $marketOptions
 */
class OperateLinkPlans extends OperatePage
{
    protected static ?string $slug = 'operate/link-plans';

    protected static ?string $navigationLabel = 'Link plans';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.operate.link-plans';

    public ?string $siteId = null;

    public ?string $proposeMarketId = null;

    public ?string $proposeTier = null;

    public function mount(): void
    {
        $candidate = request()->query('site') ?? session('guided_site_id');
        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();
        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    public function updatedSiteId(?string $value): void
    {
        if (is_string($value) && $value !== '') {
            session(['guided_site_id' => $value]);
        }
    }

    public function propose(): void
    {
        $site = $this->site();
        $market = $this->proposeMarketId !== null
            ? Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site?->id)->find($this->proposeMarketId)
            : null;
        if ($site === null || $market === null) {
            Notification::make()->warning()->title('Pick a market first.')->send();

            return;
        }

        $plan = app(LinkPlanBuilder::class)->propose($site, $market, $this->proposeTier ?: null);
        Notification::make()->success()->title("Proposed {$plan->items()->count()} link(s) for {$market->name}.")->send();
    }

    public function approveAll(string $planId): void
    {
        $plan = $this->findPlan($planId);
        if ($plan !== null) {
            $n = app(LinkPlanActions::class)->approveAll($plan);
            Notification::make()->success()->title("Approved {$n} link(s).")->send();
        }
    }

    public function rejectItem(string $itemId): void
    {
        $item = LinkPlanItem::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($itemId);
        if ($item !== null) {
            app(LinkPlanActions::class)->reject($item);
        }
    }

    public function applyPlan(string $planId): void
    {
        $plan = $this->findPlan($planId);
        if ($plan === null) {
            return;
        }
        $result = app(LinkPlanActions::class)->apply($plan, Auth::id());
        Notification::make()->success()
            ->title("Applied {$result['applied']} link(s), re-pushed {$result['republished']} page(s).")
            ->body(count($result['submitted']).' town(s) submitted to IndexNow; '.count($result['orphaned']).' held back (no inbound link).')
            ->send();
    }

    /** @return Collection<int, LinkPlan> */
    public function getPlansProperty(): Collection
    {
        if ($this->siteId === null) {
            return collect();
        }

        return LinkPlan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->with(['items.target', 'marketLocation'])
            ->latest()
            ->get();
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /** @return array<string, string> */
    public function getMarketOptionsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function site(): ?Site
    {
        return is_string($this->siteId) ? Site::query()->find($this->siteId) : null;
    }

    private function findPlan(string $planId): ?LinkPlan
    {
        return LinkPlan::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($planId);
    }
}
