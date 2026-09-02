<?php

namespace App\Reviews\Resolution;

use App\Locations\TownLocationAssigner;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Resolves a completed job's city to the owning Location by served-town membership (Review Capture §4) — the
 * system tags the review, never the customer. Pure orchestration over the existing, protected
 * {@see TownLocationAssigner::townOwners()} (which already merges served-towns ownership with authoritative
 * coverage-area ownership and honors the single-location fallback), reusing its exact town-key normalization so
 * a review resolves the same way a town page does. A town that belongs to no Location returns null → the review
 * is flagged `needs_location` for operator assignment; it is never guessed and never silently dropped.
 */
final class ReviewLocationResolver
{
    public function __construct(private readonly TownLocationAssigner $assigner) {}

    /** The owning Location id for a job city ("Trooper, PA" or "Trooper"), or null when no Location owns it. */
    public function resolve(Site $site, string $city): ?string
    {
        $key = $this->townKey($city);
        $owner = $key !== '' ? ($this->assigner->townOwners($site)[$key] ?? null) : null;
        if ($owner !== null) {
            return $owner;
        }

        // Single-location tenant: the sole location owns every town (matches TownLocationAssigner's own
        // single-location fallback) — a single-shop site never needs its towns enumerated to resolve.
        $ids = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('id');

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }

    /**
     * The same key the assigner matches on: strip a trailing ", ST" then lowercase the bare town name
     * (state is stored but not part of the match key).
     */
    private function townKey(string $city): string
    {
        $name = trim((string) preg_replace('/,\s*[A-Za-z]{2}$/', '', trim($city)));

        return mb_strtolower($name);
    }
}
