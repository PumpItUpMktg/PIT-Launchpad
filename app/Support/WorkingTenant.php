<?php

namespace App\Support;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;

/**
 * The operator's session-selected working tenant — the locked tenant chosen in the Portfolio / topbar
 * switcher ({@see ActiveTenant}, the single owner of the active-tenant session key). GEO screens
 * default their tenant selector to it so they open scoped to the same tenant the operator is already
 * working on, rather than every tenant at once. Returns null when nothing is selected (or the selection
 * no longer exists), letting the caller fall back to its own default.
 */
class WorkingTenant
{
    public static function id(): ?string
    {
        $id = app(ActiveTenant::class)->id();
        if ($id === null) {
            return null;
        }

        return Site::query()->withoutGlobalScope(SiteScope::class)->whereKey($id)->exists() ? $id : null;
    }
}
