<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\PageType;
use App\Locations\Distance;
use App\Models\Content;
use App\Models\Job;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaResolver;

/**
 * Computes what a proximity-scoped "Recent jobs near {town}" section WOULD show, per tenant — the
 * read-before-shipping data for the jobs fix (PR 5). It measures each published location page's jobs from
 * the RIGHT subject (a town page from its own centroid, a hub from the location's coordinates) against the
 * site's published jobs, using the same great-circle distance ({@see Distance::miles()}) and radius
 * ({@see ServiceAreaResolver} / `neighbour_radius_miles`) the town-coverage change uses — so the numbers
 * are what the fix will actually select, not a proxy.
 *
 * The headline is the distribution: how many pages would show 3+ jobs, how many 1–2, how many drop the
 * section (nothing in range). With a small published-jobs set most pages will drop — that is the honest
 * answer, and it's what tells us whether the radius is right before the render change ships.
 */
final class JobProximityReport
{
    /** The most jobs the section shows (the render caps at 3); "3" in the distribution means 3-or-more in range. */
    private const CARD_CAP = 3;

    public function __construct(private readonly ServiceAreaResolver $areas) {}

    /**
     * The whole portfolio, one entry per site with at least one published location page, brand-first.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $out = [];
        foreach (Site::withoutGlobalScopes()->orderBy('brand_name')->get() as $site) {
            $entry = $this->forSite($site);
            if ($entry['total'] > 0) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * One site's job-proximity census: per published location page, the count of the site's published jobs
     * within the radius of that page's subject, bucketed. `no_subject` counts pages we can't measure from
     * (a town with no resolvable centroid, or a location with no coordinates) — those drop too.
     *
     * @return array{site: Site, brand: string, radius: float, jobs: int, hub: int, town: int, total: int, dist: array{n3: int, n1_2: int, drop: int}, no_subject: int, pages: list<array{slug: string, kind: string, count: int, dropped: bool}>}
     */
    public function forSite(Site $site): array
    {
        $radius = (float) config('launchpad.link_plan.neighbour_radius_miles', 20.0);

        // The site's published jobs that carry public coordinates — the candidate pool, loaded once.
        $jobPoints = Job::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', JobStatus::Published->value)
            ->whereNotNull('lat_jittered')
            ->whereNotNull('lng_jittered')
            ->get(['lat_jittered', 'lng_jittered'])
            ->map(fn (Job $j): array => [(float) $j->lat_jittered, (float) $j->lng_jittered])
            ->all();

        // Location coordinates by id — a hub page measures from its own location.
        $locations = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['id', 'lat', 'lng'])
            ->keyBy('id');

        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['slug', 'title', 'geo_id', 'location_id', 'parent_location_id']);

        $hub = 0;
        $town = 0;
        $noSubject = 0;
        $dist = ['n3' => 0, 'n1_2' => 0, 'drop' => 0];
        $pages = [];

        foreach ($rows as $content) {
            if ($content->location_id !== null) {
                $hub++;
                $kind = 'hub';
                $loc = $locations->get($content->location_id);
                $lat = $loc !== null && $loc->lat !== null ? (float) $loc->lat : null;
                $lng = $loc !== null && $loc->lng !== null ? (float) $loc->lng : null;
            } elseif ($content->parent_location_id !== null) {
                $town++;
                $kind = 'town';
                $centroid = $this->areas->subjectCentroid((string) $site->id, $content->geo_id, (string) $content->title);
                $lat = $centroid['lat'];
                $lng = $centroid['lng'];
            } else {
                continue; // neither pin — not a composed location page
            }

            if ($lat === null || $lng === null) {
                $noSubject++;
                $dist['drop']++;
                $pages[] = ['slug' => (string) $content->slug, 'kind' => $kind, 'count' => 0, 'dropped' => true];

                continue;
            }

            $count = 0;
            foreach ($jobPoints as $point) {
                if (Distance::miles($lat, $lng, $point[0], $point[1]) <= $radius) {
                    $count++;
                }
            }

            if ($count === 0) {
                $dist['drop']++;
            } elseif ($count >= self::CARD_CAP) {
                $dist['n3']++;
            } else {
                $dist['n1_2']++;
            }

            $pages[] = ['slug' => (string) $content->slug, 'kind' => $kind, 'count' => $count, 'dropped' => $count === 0];
        }

        usort($pages, fn (array $a, array $b): int => $a['count'] <=> $b['count']);

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'radius' => $radius,
            'jobs' => count($jobPoints),
            'hub' => $hub,
            'town' => $town,
            'total' => $hub + $town,
            'dist' => $dist,
            'no_subject' => $noSubject,
            'pages' => $pages,
        ];
    }
}
