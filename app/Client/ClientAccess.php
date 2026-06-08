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
        $accountIds = $user->accounts()->pluck('accounts.id');

        return Site::query()->whereIn('account_id', $accountIds)->orderBy('brand_name')->get();
    }

    public function account(User $user): ?Account
    {
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
