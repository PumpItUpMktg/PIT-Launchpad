<?php

namespace App\Publishing\Seo;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\LocationSubject;
use App\Support\TownName;

/**
 * READ-ONLY census + deterministic rewrite plan for image alt / title / caption text that names the WRONG
 * town on a location page. The vision pass writes those fields when an image is RENDERED, under whatever
 * grounding the page had at the time; a later regenerate re-drafts the text slots but reuses the
 * already-rendered image, so its stale "…in a clean Allentown, PA basement" alt rides along onto a Neptune
 * page. The image itself is generic (a technician in a basement) — only the place name is wrong — so the fix
 * is a deterministic swap of "{WrongTown}[, ]{ST}" → "{AuthTown}, {ST}" on the render job, no fal or vision
 * spend.
 *
 * Only ANCHORED pages are assessed (an un-anchored page has no authoritative town to rewrite TO). Both the
 * comma form ("Allentown, PA") and the bare form ("Allentown PA") the vision pass emits are matched; the
 * page's own town, a bare state abbreviation, and the tenant's brand name are never treated as a town.
 */
final class ImageAltTownReport
{
    /** US state abbreviations — a captured "{X} {ST}" is only a place when ST is one of these. */
    private const STATE_ABBREVS = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY',
        'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND',
        'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC',
    ];

    /** The render-job text fields that reach the page (RenderJob::toImageObject). */
    private const FIELDS = ['alt', 'title', 'caption'];

    public function __construct(private readonly LocationSubject $subject, private readonly KnownPlaces $places) {}

    /**
     * One site's census: every succeeded render job on an anchored, published location page whose alt /
     * title / caption names a foreign town, with the exact rewrite.
     *
     * @return array{site: Site, brand: string, rows: list<array{content_id: string, slug: string, auth: string, job_id: string, slot: string, field: string, current: string, proposed: string}>, pages: int, anchored: int, unanchored: int, affected_pages: int}
     */
    public function forSite(Site $site): array
    {
        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'site_id', 'slug', 'title', 'geo_id', 'location_id', 'parent_location_id']);

        $brand = trim((string) $site->brand_name);
        $knownNames = $this->places->forSite($site)['names'];
        $rows = [];
        $anchored = 0;
        $affected = [];

        foreach ($pages as $content) {
            ['city' => $city, 'state' => $state, 'anchored' => $isAnchored] = $this->subject->resolve($content);
            if (! $isAnchored || $city === '') {
                continue; // no authoritative town to rewrite to
            }
            $anchored++;
            $authKey = TownName::key($city);
            $replacement = $state !== '' ? $city.', '.$state : $city;

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
                    $proposed = $this->rewrite($current, $authKey, $replacement, $brand, $knownNames, $state);
                    if ($proposed === $current) {
                        continue;
                    }
                    $rows[] = [
                        'content_id' => (string) $content->id,
                        'slug' => (string) $content->slug,
                        'auth' => $replacement,
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
            'brand' => $brand,
            'rows' => $rows,
            'pages' => count($pages),
            'anchored' => $anchored,
            'unanchored' => count($pages) - $anchored,
            'affected_pages' => count($affected),
        ];
    }

    /** Full state names the vision pass sometimes writes instead of the abbreviation ("Allentown, Pennsylvania"). */
    private const STATE_NAMES = [
        'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR', 'California' => 'CA',
        'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE', 'Florida' => 'FL', 'Georgia' => 'GA',
        'Hawaii' => 'HI', 'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS',
        'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD', 'Massachusetts' => 'MA',
        'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS', 'Missouri' => 'MO', 'Montana' => 'MT',
        'Nebraska' => 'NE', 'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ', 'New Mexico' => 'NM',
        'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
        'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC',
        'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT',
        'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY',
    ];

    /**
     * Regions the old grounding named alongside its town. A region is wrong on a page whose state differs
     * from the region's; its clause is dropped ("…and the surrounding Lehigh Valley area" → "…and the
     * surrounding area") rather than swapped, since no page has an authoritative region.
     */
    private const REGIONS = ['Lehigh Valley' => 'PA'];

    /** A bare town name counts as a place only in a place context: after one of these words (an article alone is not enough)… */
    private const BARE_BEFORE = '(?:[Ii]n|[Tt]hroughout|[Aa]cross|[Ss]erves|[Ss]erving|[Nn]ear|[Aa]round)';

    /** …or before one of these ("Allentown home", "Allentown-area basement", "Allentown basements"). */
    private const BARE_AFTER = '(?:-area\b|\s+(?:home|homes|basement|basements|homeowners|residents|area|communities|families|properties)\b)';

    /** Never a town when followed by one of these — "Ocean County", "Union Township" are other places. */
    private const BARE_NOT_AFTER = '(?!\s+(?:County|Township|Borough|City|Valley|Pike|Avenue|Street|Road)\b)';

    /**
     * Swap every FOREIGN place in $text for the authoritative town; returns $text unchanged when nothing
     * foreign is named. Three shapes, in order:
     *
     *  1. "{Town}[, ]{ST}" and "{Town}, {State name}" → "{Auth}, {ST}". A match is a place only when the
     *     state is real; the page's own town (or a "Serving {town}"-style phrase ending in it), a bare state
     *     code, the brand name, and a state list ("Across PA, NJ, and MD") are left alone. A leading a/an
     *     is re-agreed with the replacement.
     *  2. A bare KNOWN foreign town in a place context ("in an Allentown home", "Keeping Allentown basements
     *     dry", "serves Allentown and…", "Allentown-area") → the bare authoritative town ("in a Kearny home").
     *     Known = the site's coverage areas / locations / served towns; the context guard keeps a Title-Case
     *     "Basement Wall Crack Repair" or "Ocean County" from being read as Wall, NJ or Ocean, NJ.
     *  3. A foreign REGION clause on a page outside that region's state is dropped.
     *
     * @param  list<string>  $knownPlaces  display names of every place the site knows (longest first)
     */
    public function rewrite(string $text, string $authKey, string $replacement, string $brand, array $knownPlaces = [], string $pageState = ''): string
    {
        $brandKey = mb_strtolower($brand);
        $bare = TownName::display($replacement);

        $skip = function (string $town) use ($authKey, $brandKey): bool {
            $key = TownName::key($town);
            if ($key === '' || in_array(strtoupper($town), self::STATE_ABBREVS, true)) {
                return true;
            }
            if ($key === $authKey || str_ends_with($key, ' '.$authKey)) {
                return true; // the page's own town
            }

            return $brandKey !== '' && (str_contains($brandKey, $key) || str_contains($key, $brandKey));
        };
        $agree = function (string $article, string $with): string {
            $agreed = preg_match('/^[AEIOU]/i', $with) === 1 ? 'an' : 'a';

            return (ctype_upper($article[0]) ? ucfirst($agreed) : $agreed).' '.$with;
        };

        // 1. "{Town}[, ]{ST}" / "{Town}, {State name}"
        $stateNames = implode('|', array_map(fn (string $n): string => preg_quote($n, '/'), array_keys(self::STATE_NAMES)));
        $out = preg_replace_callback(
            '/(?:\b([Aa]n?)\s+)?\b((?:[A-Z][A-Za-z.\'\-]*)(?:\s+[A-Z][A-Za-z.\'\-]*){0,2})(?:,?\s+([A-Z]{2})\b(?!\s*(?:,|&|and)\s*(?:and\s+)?[A-Z]{2}\b)|,\s+('.$stateNames.')\b)/',
            function (array $m) use ($skip, $replacement, $agree): string {
                $article = $m[1];
                $town = trim($m[2]);
                $abbrev = $m[3] ?? '';
                if ($abbrev !== '' && ! in_array($abbrev, self::STATE_ABBREVS, true)) {
                    return $m[0]; // "{Word} XY" where XY is not a state — not a place
                }
                if ($skip($town)) {
                    return $m[0];
                }

                return $article === '' ? $replacement : $agree($article, $replacement);
            },
            $text,
        );
        $text = is_string($out) ? $out : $text;

        // 2. bare known foreign towns in a place context
        $foreign = array_values(array_filter($knownPlaces, fn (string $p): bool => ! $skip($p)));
        if ($foreign !== []) {
            $alternation = implode('|', array_map(fn (string $p): string => preg_quote($p, '/'), $foreign));
            $out = preg_replace_callback(
                '/(?:\b('.self::BARE_BEFORE.')\s+)?(?:\b([Aa]n?|[Tt]he)\s+)?\b('.$alternation.')\b'
                    .'(?!,?\s+[A-Z]{2}\b|,\s+(?:'.$stateNames.')\b)'.self::BARE_NOT_AFTER.'((?='.self::BARE_AFTER.'))?/',
                function (array $m) use ($bare, $agree): string {
                    $context = $m[1] ?? '';
                    $article = $m[2] ?? '';
                    $hasAfter = isset($m[4]);
                    if ($context === '' && ! $hasAfter) {
                        return $m[0]; // no place context — not a town
                    }
                    $lead = $article === '' ? $bare
                        : (in_array(strtolower($article), ['a', 'an'], true) ? $agree($article, $bare) : $article.' '.$bare);

                    return $context === '' ? $lead : $context.' '.$lead;
                },
                $text,
            );
            $text = is_string($out) ? $out : $text;
        }

        // 3. foreign region clauses
        foreach (self::REGIONS as $region => $regionState) {
            if ($pageState === '' || strtoupper($pageState) === $regionState) {
                continue;
            }
            $r = preg_quote($region, '/');
            $out = preg_replace(
                ['/\bsurrounding '.$r.' (area|communities)\b/', '/,?\s+and (?:the )?(?:surrounding )?'.$r.'(?: area| communities)?\b/'],
                ['surrounding $1', ''],
                $text,
            );
            $text = is_string($out) ? $out : $text;
        }

        return $text;
    }
}
