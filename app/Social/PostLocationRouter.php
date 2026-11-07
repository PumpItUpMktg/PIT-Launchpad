<?php

namespace App\Social;

use App\Models\Content;
use App\Models\ContentTown;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;

/**
 * Which physical locations a post's GBP share goes to, from the post itself: the towns it names
 * (the `content_towns` tags the publish hook writes) resolve to the locations serving them — a coverage
 * area's source locations, else a location that lists the town in its served towns. A post naming no
 * town goes to every location (null). Never a picker.
 */
final class PostLocationRouter
{
    /** @return list<string>|null location ids, or null for "every location" */
    public function forPost(Content $post): ?array
    {
        $towns = ContentTown::query()->where('content_id', $post->id)->pluck('town')->map(fn ($t): string => strtolower(trim((string) $t)))->filter()->unique()->values();
        if ($towns->isEmpty()) {
            return null;
        }
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $post->site_id)->whereNull('merged_into_id')->get();
        if ($locations->count() <= 1) {
            return null;
        }
        $ids = [];
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $post->site_id)->get(['name', 'source_location_ids']);
        foreach ($towns as $town) {
            foreach ($areas as $area) {
                if ($this->townKey((string) $area->name) === $town) {
                    foreach ((array) ($area->source_location_ids ?? []) as $id) {
                        if ($locations->firstWhere('id', $id) !== null) {
                            $ids[(string) $id] = true;
                        }
                    }
                }
            }
            foreach ($locations as $location) {
                foreach ((array) ($location->served_towns ?? []) as $served) {
                    if ($this->townKey((string) ($served['name'] ?? '')) === $town) {
                        $ids[(string) $location->id] = true;
                    }
                }
            }
        }

        return $ids === [] ? null : array_keys($ids);
    }

    private function townKey(string $name): string
    {
        return strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }
}
