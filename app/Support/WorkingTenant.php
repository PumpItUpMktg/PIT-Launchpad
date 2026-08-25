<?php

namespace App\Support;

use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The operator's session-selected working tenant — the site chosen in the cockpit (the portfolio /
 * site switcher stores it under `guided_site_id`). GEO screens default their tenant selector to it so
 * they open scoped to the same tenant the operator is already working on, rather than every tenant at
 * once. Returns null when nothing is selected (or the selection no longer exists), letting the caller
 * fall back to its own default.
 */
class WorkingTenant
{
    public static function id(): ?string
    {
        $id = session('guided_site_id');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return Site::query()->withoutGlobalScope(SiteScope::class)->whereKey($id)->exists() ? $id : null;
    }
}
