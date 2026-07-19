<?php

namespace App\Livewire;

use App\Filament\Pages\Operate\OperateDashboard;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The topbar tenant switcher — the working-tenant logo/name that opens a dropdown of every site the
 * operator can access (already limited to the permitted set by the visibility scope). Searchable above
 * ~8 entries, with "Go to Portfolio" pinned at the bottom. A single-site operator renders a STATIC
 * logo/name (no chevron, no dropdown) — the switcher only appears once there's more than one tenant to
 * switch to. Selecting sets the session tenant (the same state EnsureTenantSelected manages).
 */
class TenantSwitcher extends Component
{
    /** The count above which the dropdown shows a search box. */
    private const SEARCH_THRESHOLD = 8;

    public function switchTenant(string $siteId): void
    {
        $user = Auth::user();
        // Defense in depth: the scope already hides non-permitted sites, and the policy backs it.
        if ($user instanceof User && ! $user->canSeeSite($siteId)) {
            return;
        }
        if (! Site::query()->whereKey($siteId)->exists()) {
            return;
        }

        app(ActiveTenant::class)->set($siteId);
        $this->redirect(OperateDashboard::getUrl(), navigate: false);
    }

    /**
     * The sites this operator may switch to — scoped to the permitted set, brand-name ordered.
     *
     * @return Collection<int, Site>
     */
    public function getSitesProperty()
    {
        return Site::query()->orderBy('brand_name')->get(['id', 'brand_name']);
    }

    /** @return array{has: bool, name: string, logo_url: ?string} */
    public function getBannerProperty(): array
    {
        return app(ActiveTenant::class)->banner();
    }

    public function getSearchableProperty(): bool
    {
        return $this->getSitesProperty()->count() > self::SEARCH_THRESHOLD;
    }

    /** A single accessible tenant → static chip, no switching affordance. */
    public function getSingleProperty(): bool
    {
        return $this->getSitesProperty()->count() <= 1;
    }

    public function render(): View
    {
        return view('livewire.tenant-switcher');
    }
}
