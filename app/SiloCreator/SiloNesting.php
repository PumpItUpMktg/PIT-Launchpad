<?php

namespace App\SiloCreator;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Locations\LocationNesting;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Nests each child SERVICE page under its silo HUB — the URL-nesting behind /drain-services/drain-cleaning,
 * so the built page structure matches the silo tree the operator sees on Silos & pruning (a silo's pillar
 * hub with its child services beneath it). The exact analog of {@see LocationNesting}, keyed
 * on `silo_id` instead of location: a materialized silo produces one `page_type=hub` page (the pillar) and
 * `page_type=service` pages (the child spokes), all sharing the silo. For every child service page, it:
 *
 *  - pins `parent_content_id` = the silo's hub page (the WP post_parent + permalink parent), and
 *  - rewrites the service's slug to `{hubSlug}/{serviceSegment}` — the full nested path.
 *
 * Runs at materialize, after every page exists. Idempotent: the segment is recomputed from the page's own
 * title and the parent from the current hub slug, so a re-run (or a hub rename) self-heals to the same
 * nested slug and never re-suffixes. A silo with no hub (no pillar), or a hub-less service, is left flat.
 */
final class SiloNesting
{
    public function nest(Site $site): void
    {
        // The silo hub (pillar) pages, keyed by silo — one per silo.
        $hubs = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Hub->value)
            ->whereNotNull('silo_id')
            ->get()
            ->keyBy(fn (Content $c): string => (string) $c->silo_id);

        if ($hubs->isEmpty()) {
            return;
        }

        $servicePages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Service->value)
            ->whereNotNull('silo_id')
            ->whereNotNull('slug')
            ->get();

        // The slugs already in use — so a rewritten nested slug stays site-wide unique.
        $taken = Content::withoutGlobalScope(SiteScope::class)
            ->withTrashed()
            ->where('site_id', $site->id)
            ->pluck('slug')
            ->map(fn ($s): string => (string) $s)
            ->all();

        foreach ($servicePages as $service) {
            $hub = $hubs->get((string) $service->silo_id);
            if ($hub === null || $hub->id === $service->id) {
                continue; // no hub to nest under — leave the service flat
            }

            $hubSlug = trim((string) $hub->slug, '/');
            $segment = Str::slug((string) $service->title) ?: 'page';
            $nested = $this->uniqueNested($hubSlug, $segment, $taken, (string) $service->slug);

            $changed = false;
            if ((string) $service->parent_content_id !== (string) $hub->id) {
                $service->parent_content_id = $hub->id;
                $changed = true;
            }
            if ((string) $service->slug !== $nested) {
                // free the old slug for reuse and reserve the new one within this pass
                $taken = array_values(array_filter($taken, fn (string $s): bool => $s !== (string) $service->slug));
                $taken[] = $nested;
                $service->slug = $nested;
                $changed = true;
            }

            if ($changed) {
                $service->save();
            }
        }
    }

    /**
     * `{hubSlug}/{segment}`, disambiguated against the taken set (the page's own current slug is not a
     * collision). Two services under the same hub can't share a title in practice, so a suffix is rare.
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
