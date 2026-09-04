<?php

namespace App\Models\Concerns;

use App\Models\Scopes\SiteScope;
use App\Models\Scopes\VisibleTenantScope;
use App\Models\Site;
use App\Security\CrossTenantWriteException;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies single-database, row-level tenancy to a model: a global scope keyed
 * on the resolved current site_id, automatic population of site_id on create
 * when a site is resolved, and a WRITE GUARD that refuses to save or delete a
 * row belonging to a different tenant while one is locked. Global (non-tenant)
 * records opt out by simply not using this trait.
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope(new SiteScope);
        static::addGlobalScope(new VisibleTenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('site_id') === null && CurrentSite::id() !== null) {
                $model->setAttribute('site_id', CurrentSite::id());
            }
        });

        // Write guard (the wrong-site-publish guard): once a tenant is locked, a save or delete of a
        // row for ANOTHER tenant is refused loudly. A no-op when no tenant is locked (jobs, console,
        // lobby) or when the row's site_id isn't set yet (a fresh create — `creating` above fills it),
        // mirroring SiteScope so legitimate cross-tenant work (always run with no lock) is unaffected.
        static::saving(fn (Model $model) => self::assertSameTenantWrite($model));
        static::deleting(fn (Model $model) => self::assertSameTenantWrite($model));
    }

    private static function assertSameTenantWrite(Model $model): void
    {
        $locked = CurrentSite::id();
        $rowSite = $model->getAttribute('site_id');

        if ($locked !== null && $rowSite !== null && $rowSite !== $locked) {
            throw CrossTenantWriteException::for($model::class, (string) $rowSite, $locked);
        }
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
