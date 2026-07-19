<?php

namespace App\Models\Concerns;

use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleTenantScope;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies single-database, row-level tenancy to a model: a global scope keyed
 * on the resolved current site_id, plus automatic population of site_id on
 * create when a site is resolved. Global (non-tenant) records opt out by simply
 * not using this trait.
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope(new SiteScope);
        static::addGlobalScope(new VisibleTenantScope);

        static::creating(function (self $model): void {
            if ($model->getAttribute('site_id') === null && CurrentSite::id() !== null) {
                $model->setAttribute('site_id', CurrentSite::id());
            }
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
