<?php

namespace App\Models;

use App\Enums\MunicipalityType;
use App\Locations\CoverageBand;
use App\Locations\CoverageName;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\CoverageAreaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One municipality in a tenant's authoritative service-area coverage set (the union of
 * the Census enumeration across all base Locations' radii). The Phase-3 dependency:
 * keyword volume is localized against this set. A selected subset becomes the
 * location-page Markets (a later layer).
 *
 * @property string $id
 * @property string $site_id
 * @property string $geo_id
 * @property string $name
 * @property MunicipalityType $type
 * @property string|null $state
 * @property float|null $lat
 * @property float|null $lng
 * @property float|null $distance_miles
 * @property list<string>|null $source_location_ids
 * @property int|null $population ACS5 total population (for Large/Medium/Small grouping)
 * @property string|null $size_tier major|large|medium|small (derived from population; null = ungrouped)
 * @property string|null $band the roll-out band ({@see CoverageBand}): the size tier for a county market, a distance ring (ring5…) for a proximity one; null = ungrouped
 * @property bool $page_selected in the location-page drip pool
 * @property string $source county (auto) | manual (owner-added, directed → priority page candidate)
 */
class CoverageArea extends Model
{
    /** @use HasFactory<CoverageAreaFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * A row created without an explicit band is a county-mode row, whose band IS its size tier — the
     * one creation seam, so every legacy write path (and every fixture) lands a band the gate can read.
     * The coverage writer sets the band explicitly (a distance ring for a proximity market).
     */
    protected static function booted(): void
    {
        static::creating(function (CoverageArea $area): void {
            if (! array_key_exists('band', $area->getAttributes())) {
                $area->band = $area->size_tier;
            }
        });
    }

    /**
     * Normalize the town name on the way in — strips a leading numbered-list artifact ("6, Havre de
     * Grace" → "Havre de Grace") so no write path (import, served-towns sync, manual add) can land the
     * junk that leaked the "6-havre-de-grace-md" page title + slug. See {@see CoverageName}.
     *
     * Set-only: with no `get`, Eloquent hands back the stored string, so the read type is `string`.
     * It was annotated `Attribute<never, string>`, which told PHPStan that reading `->name` never
     * returns — every branch after a read was then "unreachable", and callers worked around it by
     * reaching for `getAttribute('name')` instead.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => CoverageName::clean((string) $value),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MunicipalityType::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'distance_miles' => 'decimal:2',
            'source_location_ids' => 'array',
            'page_selected' => 'boolean',
        ];
    }
}
