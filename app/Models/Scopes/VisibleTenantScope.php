<?php

namespace App\Models\Scopes;

use App\Support\CurrentSite;
use App\Support\SiteVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Gating layer 1 for site-scoped models in CROSS-TENANT mode. When a working tenant is selected,
 * {@see SiteScope} already narrows to that one (permitted) site. When none is selected — the all-sites
 * aggregates (Dashboard tiles, Blog queues, boards in all-sites mode) — this constrains rows to the
 * actor's permitted sites so a restricted operator never sees another tenant's data. No-op for
 * unrestricted actors, and skipped entirely while a single site is scoped (SiteScope owns that case).
 */
final class VisibleTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (CurrentSite::id() !== null) {
            return; // single-site mode — SiteScope already narrows to the (permitted) working tenant
        }

        $permitted = SiteVisibility::permittedIds();
        if ($permitted !== null) {
            $builder->whereIn($model->getTable().'.site_id', $permitted);
        }
    }
}
