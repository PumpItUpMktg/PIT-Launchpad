<?php

namespace App\Models\Scopes;

use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts queries to the resolved current site. When no site is resolved
 * (e.g. operator/global context, console, seeding) the scope is a no-op.
 */
class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $siteId = CurrentSite::id();

        if ($siteId !== null) {
            $builder->where($model->getTable().'.site_id', $siteId);
        }
    }
}
