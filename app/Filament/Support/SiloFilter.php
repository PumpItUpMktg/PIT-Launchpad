<?php

namespace App\Filament\Support;

use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use Filament\Tables\Filters\SelectFilter;

/**
 * A Silo table filter whose options are limited to the tenant selected in the sibling `site_id` (Tenant)
 * filter — so an operator never sees another tenant's silos in the dropdown. `Silo` is site-scoped, but
 * the operator panel drops that scope for cross-tenant reads, which is why a plain
 * `->relationship('silo', 'name')` lists every tenant's silos; this scopes the options explicitly.
 */
final class SiloFilter
{
    public static function scopedToTenant(): SelectFilter
    {
        return SelectFilter::make('silo_id')->label('Silo')
            ->options(fn ($livewire): array => self::optionsForTenant($livewire->getTableFilterState('site_id')['value'] ?? null));
    }

    /**
     * The silo name=>id options for one tenant. Empty when no tenant is selected (pick a tenant first) so
     * the dropdown never spans tenants.
     *
     * @return array<string, string>
     */
    public static function optionsForTenant(mixed $siteId): array
    {
        if (! is_string($siteId) || $siteId === '') {
            return [];
        }

        return Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
