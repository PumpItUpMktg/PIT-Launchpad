<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides Location rows that the NAP reconcile has folded into another row (`merged_into_id` set). The
 * retired row stays in the table — reversible by nulling the column — but vanishes from every normal
 * query, so it never grows a duplicate hub page and never shows in directories. The reconcile itself
 * (and any audit) drops this scope with `withoutGlobalScope(ActiveLocationScope::class)` to see the
 * merged rows.
 */
class ActiveLocationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.merged_into_id');
    }
}
