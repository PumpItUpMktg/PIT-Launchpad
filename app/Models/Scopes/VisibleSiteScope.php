<?php

namespace App\Models\Scopes;

use App\Support\SiteVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Gating layer 1 for the `Site` model itself — constrains every `Site` query to the actor's permitted
 * set (Portfolio, the switcher list, and every place that resolves a working tenant via `Site::query()`
 * all flow through this, so a non-permitted site simply doesn't exist for that operator). No-op for
 * unrestricted actors (admin / no-membership operator / console). Drop it with `withoutGlobalScope`
 * for genuinely cross-tenant operator tooling.
 */
final class VisibleSiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $permitted = SiteVisibility::permittedIds();
        if ($permitted !== null) {
            $builder->whereIn($model->getTable().'.id', $permitted);
        }
    }
}
