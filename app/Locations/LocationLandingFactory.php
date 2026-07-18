<?php

namespace App\Locations;

use App\Build\Permalinks;
use App\Console\Commands\GenerateLocationCommand;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\SiloCreator\PillarFactory;

/**
 * Find-or-creates the ONE landing/hub page for a base GBP/service Location — the `kind=page` Content
 * pinned to the location (`location_id` set, titled "{City}, {ST}", kit-resolved). This is "the page
 * that IS the location" (e.g. "…| Montclair"); its town pages nest beneath it (parent_location_id).
 *
 * Idempotent: keyed on `location_id`, so re-running reuses the same row, never a duplicate. Shared by
 * the build ({@see LocationLandingSync}) and the operator command ({@see GenerateLocationCommand}).
 */
final class LocationLandingFactory
{
    public function __construct(private readonly Permalinks $permalinks) {}

    public function findOrCreate(Location $location): Content
    {
        $existing = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $location->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        ['city' => $city, 'state' => $state] = $location->cityState();
        if ($city === '') {
            $city = trim((string) $location->name);
        }
        $title = $city !== ''
            ? ($state !== '' ? "{$city}, {$state}" : $city)
            : (trim((string) $location->name) !== '' ? trim((string) $location->name) : 'Our location');

        /** @var Site $site */
        $site = Site::withoutGlobalScope(SiteScope::class)->findOrFail($location->site_id);
        $slug = $this->permalinks->uniqueSlug($title, $this->permalinks->takenSlugs($site));
        $kit = PillarFactory::resolveKit(PageType::Location, (string) $location->site_id);

        return Content::create([
            'site_id' => $location->site_id,
            'kind' => ContentKind::Page,
            'page_type' => PageType::Location,
            'status' => ContentStatus::Candidate,
            'title' => $title,
            'slug' => $slug,
            'version' => 1,
            'location_id' => $location->id,
            'wireframe_kit_id' => $kit?->id,
            'wireframe_kit_version' => $kit?->version,
        ]);
    }
}
