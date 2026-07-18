<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Nests each TOWN page under its location HUB — the URL-nesting step behind /montclair/springfield.
 * Runs at materialize after {@see LocationLandingSync} (hubs exist) and {@see TownLocationAssigner}
 * (towns pinned to their physical Location). For every town page (page_type=location, no own
 * `location_id`, a `parent_location_id`), it resolves that location's hub landing page and:
 *
 *  - pins `parent_content_id` = hub id (the WP post_parent + permalink parent), and
 *  - rewrites the town's slug to `{hubSlug}/{townSegment}` — the full nested path.
 *
 * Storing the FULL path as the slug is what lets duplicate town names coexist: the `(site_id, slug)`
 * unique index sees `montclair-nj/springfield` and `trooper-pa/springfield` as distinct, so a
 * "Springfield" served by two locations no longer collides into `springfield` / `springfield-2`.
 *
 * Idempotent: the town segment is recomputed from the page's own title, so a re-run lands the same
 * nested slug and never re-suffixes. A town whose hub is missing (nothing to nest under) is left flat.
 */
final class LocationNesting
{
    public function nest(Site $site): void
    {
        // Hub landing pages by the Location they represent (location_id → hub Content).
        $hubs = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNotNull('location_id')
            ->get()
            ->keyBy(fn (Content $c): string => (string) $c->location_id);

        if ($hubs->isEmpty()) {
            return;
        }

        $townPages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')          // a town under a hub, not a hub itself
            ->whereNotNull('parent_location_id') // assigned to a physical Location by TownLocationAssigner
            ->get();

        // The slugs already in use — so a rewritten nested slug stays site-wide unique.
        $taken = Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $site->id)
            ->pluck('slug')
            ->map(fn ($s): string => (string) $s)
            ->all();

        foreach ($townPages as $town) {
            $hub = $hubs->get((string) $town->parent_location_id);
            if ($hub === null || $hub->id === $town->id) {
                continue; // no hub to nest under — leave the town flat
            }

            $hubSlug = trim((string) $hub->slug, '/');
            $segment = Str::slug((string) $town->title) ?: 'page';
            $nested = $this->uniqueNested($hubSlug, $segment, $taken, (string) $town->slug);

            $changed = false;
            if ((string) $town->parent_content_id !== (string) $hub->id) {
                $town->parent_content_id = $hub->id;
                $changed = true;
            }
            if ((string) $town->slug !== $nested) {
                // free the old slug for reuse and reserve the new one within this pass
                $taken = array_values(array_filter($taken, fn (string $s): bool => $s !== (string) $town->slug));
                $taken[] = $nested;
                $town->slug = $nested;
                $changed = true;
            }

            if ($changed) {
                $town->save();
            }
        }
    }

    /**
     * `{hubSlug}/{segment}`, disambiguated against the taken set (the town's own current slug is not a
     * collision). Duplicate town names live under different hubs, so a suffix is rarely needed.
     *
     * @param  list<string>  $taken
     */
    private function uniqueNested(string $hubSlug, string $segment, array $taken, string $self): string
    {
        $candidate = $hubSlug.'/'.$segment;
        $n = 1;
        while ($candidate !== $self && in_array($candidate, $taken, true)) {
            $candidate = $hubSlug.'/'.$segment.'-'.(++$n);
        }

        return $candidate;
    }
}
