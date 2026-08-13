<?php

namespace App\Client;

use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolves what a client may see: the Site(s) under their Account(s). A client is
 * Account-scoped — they never see another Account's data — and picks a Site via
 * the switcher when their Account owns several.
 */
class ClientAccess
{
    /**
     * @return Collection<int, Site>
     */
    public function sites(User $user): Collection
    {
        // A platform super-user sees every client's site (the all-clients switcher).
        if ($user->isPlatformSuperUser()) {
            return Site::query()->whereNotNull('account_id')->orderBy('brand_name')->get();
        }

        $accountIds = $user->accounts()->pluck('accounts.id');

        return Site::query()->whereIn('account_id', $accountIds)->orderBy('brand_name')->get();
    }

    public function account(User $user): ?Account
    {
        // For a super-user the brand follows the site they've switched to, so the portal white-labels
        // to whichever client they're viewing (rather than an account they don't belong to).
        if ($user->isPlatformSuperUser()) {
            $selected = session('client_site_id');
            $site = $this->currentSite($user, is_string($selected) ? $selected : null);

            return $site !== null ? Account::query()->find($site->account_id) : Account::query()->orderBy('brand_name')->first();
        }

        return $user->accounts()->first();
    }

    /**
     * The current Site: the switcher's selection if it belongs to the client,
     * else their first Site.
     */
    public function currentSite(User $user, ?string $selectedId = null): ?Site
    {
        $sites = $this->sites($user);

        if ($selectedId !== null) {
            $selected = $sites->firstWhere('id', $selectedId);
            if ($selected !== null) {
                return $selected;
            }
        }

        return $sites->first();
    }

    public function canSee(User $user, Site $site): bool
    {
        return $this->sites($user)->contains('id', $site->id);
    }
}
