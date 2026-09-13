<?php

namespace App\Publishing\Seo;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\LocationSubject;

/**
 * READ-ONLY census + deterministic UNDO for the three mis-rewrites an earlier image alt/title/caption pass
 * wrote before its guards were fixed (#841). Each has one shape and one inverse, keyed on the page's own
 * authoritative town, so the damage is reversible without any record of the original text:
 *
 *  1. state list  — "across Belleville, NJ, and Maryland"   → "across New Jersey, Pennsylvania, and Maryland"
 *                   (the two named states were the page's state and the one site state missing from the
 *                   phrase; page state first). Ambiguous when more than one site state is missing — reported,
 *                   never guessed.
 *  2. region      — "the surrounding Lehigh Howell area"   → "the surrounding Lehigh Valley area"
 *  3. doubled town — "in an Upper Upper Darby home"        → "in an Upper Darby home"
 *
 * The forward rewrite ({@see ImageAltTownReport}) cannot see these — every one contains the page's own town,
 * which it rightly never touches — hence a separate repair. Only anchored pages are assessed.
 */
final class ImageAltMangleRepair
{
    private const FIELDS = ['alt', 'title', 'caption'];

    public function __construct(private readonly LocationSubject $subject) {}

    /**
     * @param  list<string>|null  $states  the states the site's boilerplate lists (2-letter); null → derived
     *                                     from the site's GBP locations ({@see siteStates})
     * @return array{site: Site, brand: string, rows: list<array{content_id: string, slug: string, auth: string, job_id: string, slot: string, field: string, current: string, proposed: string}>, unresolved: list<array{slug: string, field: string, current: string}>, pages: int, anchored: int, unanchored: int, affected_pages: int, states: list<string>}
     */
    public function forSite(Site $site, ?array $states = null): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'slug', 'title', 'geo_id', 'location_id', 'parent_location_id']);

        $siteStates = $states ?? $this->siteStates($site);
        $rows = [];
        $unresolved = [];
        $anchored = 0;
        $affected = [];

        foreach ($pages as $content) {
            ['city' => $city, 'state' => $state, 'anchored' => $isAnchored] = $this->subject->resolve($content);
            if (! $isAnchored || $city === '' || $state === '') {
                continue;
            }
            $anchored++;

            $jobs = RenderJob::withoutGlobalScope(SiteScope::class)
                ->where('content_id', $content->id)
                ->where('status', RenderStatus::Succeeded->value)
                ->whereNotNull('slot')
                ->get(['id', 'slot', 'alt', 'title', 'caption']);

            foreach ($jobs as $job) {
                foreach (self::FIELDS as $field) {
                    $current = trim((string) $job->getAttribute($field));
                    if ($current === '') {
                        continue;
                    }
                    $proposed = $this->repair($current, $city, $state, $siteStates);
                    if ($this->hasStateListMangle($proposed, $city, $state)) {
                        $unresolved[] = ['slug' => (string) $content->slug, 'field' => $field, 'current' => $current];
                    }
                    if ($proposed === $current) {
                        continue;
                    }
                    $rows[] = [
                        'content_id' => (string) $content->id,
                        'slug' => (string) $content->slug,
                        'auth' => $city.', '.$state,
                        'job_id' => (string) $job->id,
                        'slot' => (string) $job->slot,
                        'field' => $field,
                        'current' => $current,
                        'proposed' => $proposed,
                    ];
                    $affected[(string) $content->id] = true;
                }
            }
        }

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'rows' => $rows,
            'unresolved' => $unresolved,
            'pages' => count($pages),
            'anchored' => $anchored,
            'unanchored' => count($pages) - $anchored,
            'affected_pages' => count($affected),
            'states' => $siteStates,
        ];
    }

    /**
     * Undo the three mis-rewrites in $text for a page whose own town is "{$city}, {$state}"; unchanged when
     * none is present. A state list whose missing state is ambiguous is left as-is.
     *
     * @param  list<string>  $siteStates  the states the site operates in (2-letter, upper-case)
     */
    public function repair(string $text, string $city, string $state, array $siteStates): string
    {
        $cityQ = preg_quote($city, '/');

        // 3. doubled town — the own town's first word repeated before it ("Upper Upper Darby")
        $words = explode(' ', $city);
        if (count($words) >= 2) {
            $out = preg_replace('/\b'.preg_quote($words[0], '/').' '.$cityQ.'\b/', $city, $text);
            $text = is_string($out) ? $out : $text;
        }

        // 2. region — the region's last word replaced by the own town ("Lehigh Howell" → "Lehigh Valley")
        foreach (array_keys(ImageAltTownReport::REGIONS) as $region) {
            $regionWords = explode(' ', $region);
            if (count($regionWords) < 2) {
                continue;
            }
            $head = implode(' ', array_slice($regionWords, 0, -1));
            $out = preg_replace('/\b'.preg_quote($head, '/').' '.$cityQ.'\b/', $region, $text);
            $text = is_string($out) ? $out : $text;
        }

        // 1. state list — "{City}, {ST}, and {StateName}" was "{PageState}, {MissingState}, and {StateName}"
        $names = ImageAltTownReport::STATE_NAMES;
        $byAbbrev = array_flip($names);
        $stateNames = implode('|', array_map(fn (string $n): string => preg_quote($n, '/'), array_keys($names)));
        $out = preg_replace_callback(
            '/\b'.$cityQ.', '.preg_quote($state, '/').', (and|or) ('.$stateNames.')\b/',
            function (array $m) use ($state, $siteStates, $names, $byAbbrev): string {
                $trailing = $names[$m[2]] ?? '';
                $missing = array_values(array_diff($siteStates, [$state, $trailing]));
                if (count($missing) !== 1 || ! isset($byAbbrev[$state], $byAbbrev[$missing[0]])) {
                    return $m[0]; // ambiguous — reported as unresolved, never guessed
                }

                return $byAbbrev[$state].', '.$byAbbrev[$missing[0]].', '.$m[1].' '.$m[2];
            },
            $text,
        );

        return is_string($out) ? $out : $text;
    }

    /** True when $text still carries the "{City}, {ST}, and/or {StateName}" shape (an unresolved state list). */
    public function hasStateListMangle(string $text, string $city, string $state): bool
    {
        $stateNames = implode('|', array_map(fn (string $n): string => preg_quote($n, '/'), array_keys(ImageAltTownReport::STATE_NAMES)));

        return preg_match('/\b'.preg_quote($city, '/').', '.preg_quote($state, '/').', (?:and|or) (?:'.$stateNames.')\b/', $text) === 1;
    }

    /**
     * The states the site's boilerplate lists: the states of its GBP LOCATIONS (where the business sits —
     * "New Jersey, Pennsylvania" for NJ + PA hubs), not every county it covers (coverage areas reach into
     * NY / DE / MD from border counties, which would make the missing state ambiguous). Falls back to
     * coverage-area states only when no location carries a state.
     *
     * @return list<string>
     */
    private function siteStates(Site $site): array
    {
        /** @var list<string> $states */
        $states = [];
        $add = function (string $st) use (&$states): void {
            $st = strtoupper(trim($st));
            if ($st !== '' && ! in_array($st, $states, true)) {
                $states[] = $st;
            }
        };
        foreach (Location::withoutGlobalScopes()->where('site_id', $site->id)->get() as $location) {
            $add($location->cityState()['state']);
        }
        if ($states !== []) {
            return $states;
        }
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->whereNotNull('state')->distinct()->pluck('state') as $st) {
            $add((string) $st);
        }

        return $states;
    }
}
