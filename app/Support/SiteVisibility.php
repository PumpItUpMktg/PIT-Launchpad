<?php

namespace App\Support;

use App\Models\Scopes\VisibleSiteScope;
use App\Models\Scopes\VisibleTenantScope;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves which sites the CURRENT actor may see — the single source the two visibility global scopes
 * ({@see VisibleSiteScope}, {@see VisibleTenantScope}) consult.
 * Returns null (unrestricted) when there is no authenticated user (console / queue / seeders), when the
 * user is an admin, or when an operator carries no membership rows (back-compat). An operator WITH
 * memberships is restricted to that set — the gating's query-scoping layer.
 */
final class SiteVisibility
{
    /**
     * @return list<string>|null permitted site ids, or null for unrestricted
     */
    public static function permittedIds(): ?array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->permittedSiteIds() : null;
    }
}
