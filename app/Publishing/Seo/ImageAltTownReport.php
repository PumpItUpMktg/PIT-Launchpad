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

    public function __construct(private readonly LocationSubject $subject) {}

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
                    $proposed = $this->rewrite($current, $authKey, $replacement, $brand);
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

    /**
     * Swap every FOREIGN "{Town}[, ]{ST}" in $text for the authoritative "{Town}, {ST}"; returns $text
     * unchanged when nothing foreign is named. A match is a place only when ST is a real state; the page's
     * own town (or a "Serving {town}"-style phrase ending in it), a bare state code, and the brand name are
     * left alone. A state that is itself followed by another state (", NJ", "& MD", "and MD") is a STATE LIST
     * ("Service Across PA, NJ, and MD"), never a place — the words before it are prose, not a town. A leading
     * "a"/"an" is re-agreed with the replacement ("in an Allentown, PA basement" → "in a Neptune, NJ basement").
     */
    public function rewrite(string $text, string $authKey, string $replacement, string $brand): string
    {
        $brandKey = mb_strtolower($brand);

        $out = preg_replace_callback(
            '/(?:\b([Aa]n?)\s+)?\b((?:[A-Z][A-Za-z.\'\-]*)(?:\s+[A-Z][A-Za-z.\'\-]*){0,2}),?\s+([A-Z]{2})\b(?!\s*(?:,|&|and)\s*(?:and\s+)?[A-Z]{2}\b)/',
            function (array $m) use ($authKey, $replacement, $brandKey): string {
                $article = $m[1];
                $town = trim($m[2]);
                $key = TownName::key($town);

                if (! in_array($m[3], self::STATE_ABBREVS, true)) {
                    return $m[0]; // "{Word} XY" where XY is not a state — not a place
                }
                if ($key === '' || in_array(strtoupper($town), self::STATE_ABBREVS, true)) {
                    return $m[0];
                }
                if ($key === $authKey || str_ends_with($key, ' '.$authKey)) {
                    return $m[0]; // the page's own town
                }
                if ($brandKey !== '' && (str_contains($brandKey, $key) || str_contains($key, $brandKey))) {
                    return $m[0]; // brand words ("Sump Pump Gurus NJ") are not a town
                }
                if ($article === '') {
                    return $replacement;
                }
                $agreed = preg_match('/^[AEIOU]/i', $replacement) === 1 ? 'an' : 'a';

                return (ctype_upper($article[0]) ? ucfirst($agreed) : $agreed).' '.$replacement;
            },
            $text,
        );

        return is_string($out) ? $out : $text;
    }
}
