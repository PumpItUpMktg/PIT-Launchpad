<?php

namespace App\Operator;

use App\Http\Middleware\EnsureTenantSelected;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteBranding;

/**
 * The operator's currently-selected working tenant — the single session-backed "who am I working
 * on" for the whole admin panel. Reuses the `guided_site_id` session key the Setup/Operate pages
 * already share, so selecting a tenant here is the same selection those pages read.
 *
 * The panel is operator-only (User::canAccessPanel), so this is always an operator context; the
 * hard gate ({@see EnsureTenantSelected}) guarantees a selection before any
 * tenant-scoped page renders, and the topbar banner shows it (logo + name) on every page.
 */
class ActiveTenant
{
    public const SESSION_KEY = 'guided_site_id';

    /** The selected tenant's id, or null when none is chosen (gate → Portfolio). */
    public function id(): ?string
    {
        $id = session(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Select the working tenant (persisted for every page). */
    public function set(string $siteId): void
    {
        session([self::SESSION_KEY => $siteId]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function has(): bool
    {
        return $this->site() !== null;
    }

    /** The selected Site, or null. Guards against a stale/deleted id in the session. */
    public function site(): ?Site
    {
        $id = $this->id();

        return $id === null ? null : Site::query()->find($id);
    }

    /**
     * The banner view-model for the topbar chrome: the tenant's name + logo (the site's own uploaded
     * logo, else its Account's white-label logo, else none → name only).
     *
     * @return array{has: bool, name: string, logo_url: ?string}
     */
    public function banner(): array
    {
        $site = $this->site();
        if ($site === null) {
            return ['has' => false, 'name' => '', 'logo_url' => null];
        }

        return [
            'has' => true,
            'name' => trim((string) $site->brand_name) !== '' ? (string) $site->brand_name : 'Untitled tenant',
            'logo_url' => $this->logoUrl($site),
        ];
    }

    /** The site's own uploaded logo (SiteBranding.logo_set) → its Account's logo → null. */
    private function logoUrl(Site $site): ?string
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $set = is_array($branding?->logo_set) ? $branding->logo_set : [];
        $siteLogo = trim((string) ($set['url'] ?? ''));
        if ($siteLogo !== '') {
            return $siteLogo;
        }

        $accountLogo = trim((string) ($site->account->logo_url ?? ''));

        return $accountLogo !== '' ? $accountLogo : null;
    }
}
