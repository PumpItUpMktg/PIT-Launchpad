<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\LocationSubject;
use App\Support\TownName;

/**
 * READ-ONLY diagnostic that scopes the REGENERATE set: which anchored location pages carry a hallucinated
 * town in their DRAFTED content, not just the title. The deterministic-title fix corrects the <title>, but
 * the drafted slots (hero headline, intro, FAQ) were generated with the drifted grounding and still name the
 * wrong town — a repush re-renders those slots as-is, so only a re-draft (regenerate) can fix them. This
 * finds those pages so the regenerate is scoped by computed fact, not guessed.
 *
 * The high-precision signal is the HERO HEADLINE (the H1): a coverage neighbour is never named there, so an
 * "{City}, {ST}" in the H1 whose city isn't the page's authoritative town is a drift signal (e.g. "… in
 * Allentown, PA" on the Neptune page). Body slots are scanned too, but only as a depth indicator — drafted
 * prose can legitimately mention nearby towns, so a body hit alone never sets the flag.
 *
 * Only ANCHORED pages are assessed: an un-anchored page has no authoritative town to judge against.
 */
final class LocationBodyReport
{
    /** US state abbreviations — a captured "{X}, {ST}" whose X is one of these is a REGION phrase, not a town. */
    private const STATE_ABBREVS = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY',
        'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND',
        'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC',
    ];

    public function __construct(private readonly LocationSubject $subject) {}

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
     * One site's drafted-body census. `pages` is foreign-town first, then by body-hit depth.
     *
     * @return array{site: Site, brand: string, pages: list<array{slug: string, auth: string, h1: string, status: string, foreign: list<string>, body_hits: int}>, total: int, anchored: int, foreign_town: int, weak: int, ok: int}
     */
    public function forSite(Site $site): array
    {
        $rows = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('status', ContentStatus::Published->value)
            ->get(['site_id', 'slug', 'title', 'slot_payload', 'geo_id', 'location_id', 'parent_location_id']);

        $pages = [];
        $anchored = 0;
        $foreignTown = 0;
        $weak = 0;
        $ok = 0;

        foreach ($rows as $content) {
            ['city' => $authCity, 'anchored' => $isAnchored] = $this->subject->resolve($content);
            if (! $isAnchored || $authCity === '') {
                continue; // only anchored pages can be judged against an authoritative town
            }
            $anchored++;
            $authKey = TownName::key($authCity);

            $slots = is_array($content->slot_payload) ? $content->slot_payload : [];
            $h1 = $this->heroHeadline($slots);
            $bodyForeign = $this->foreignTowns($this->flatten($slots), $authKey);

            $status = $this->foreignTowns($h1, $authKey) !== []
                ? 'foreign_town'
                : (mb_stripos($h1, $authCity) !== false ? 'ok' : 'weak');

            $foreignTown += $status === 'foreign_town' ? 1 : 0;
            $weak += $status === 'weak' ? 1 : 0;
            $ok += $status === 'ok' ? 1 : 0;

            $pages[] = [
                'slug' => (string) $content->slug,
                'auth' => $authCity,
                'h1' => $h1,
                'status' => $status,
                'foreign' => array_values(array_unique($bodyForeign)),
                'body_hits' => count($bodyForeign),
            ];
        }

        usort($pages, function (array $a, array $b): int {
            $rank = fn (array $p): int => $p['status'] === 'foreign_town' ? 2 : ($p['status'] === 'weak' ? 1 : 0);

            return [$rank($b), $b['body_hits']] <=> [$rank($a), $a['body_hits']];
        });

        return [
            'site' => $site,
            'brand' => trim((string) $site->brand_name),
            'pages' => $pages,
            'total' => count($pages),
            'anchored' => $anchored,
            'foreign_town' => $foreignTown,
            'weak' => $weak,
            'ok' => $ok,
        ];
    }

    /**
     * The distinct "{City}, {ST}" town names in $text whose city is NOT the authoritative town and is not a
     * bare state region. Town words must be Capitalized and the state a 2-letter uppercase code, so a
     * connective ("… in Allentown, PA" → "Allentown", never "in Allentown") and a region ("NJ, PA") are
     * handled.
     *
     * @return list<string>
     */
    private function foreignTowns(string $text, string $authKey): array
    {
        if (preg_match_all('/((?:[A-Z][A-Za-z.\'\-]*)(?:\s+[A-Z][A-Za-z.\'\-]*){0,3}),\s*([A-Z]{2})\b/', $text, $m, PREG_SET_ORDER) === false) {
            return [];
        }

        $out = [];
        foreach ($m as $match) {
            $city = trim($match[1]);
            $key = TownName::key($city);
            if ($key === '' || in_array(strtoupper($city), self::STATE_ABBREVS, true)) {
                continue;
            }
            // Not foreign if it IS the town, or the captured phrase merely picked up a leading verb/filler
            // with it ("Serving Neptune" / "near Neptune" → ends with the authoritative town).
            if ($key === $authKey || ($authKey !== '' && str_ends_with($key, ' '.$authKey))) {
                continue;
            }
            $out[] = $city;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function heroHeadline(array $slots): string
    {
        $raw = $slots['hero_headline'] ?? '';

        return is_array($raw) ? trim((string) ($raw[0] ?? '')) : trim((string) $raw);
    }

    /**
     * Flatten every string leaf in the drafted slot_payload into one text blob (order-independent).
     *
     * @param  array<string, mixed>  $slots
     */
    private function flatten(array $slots): string
    {
        $parts = [];
        array_walk_recursive($slots, function ($v) use (&$parts): void {
            if (is_string($v)) {
                $parts[] = $v;
            }
        });

        return implode("\n", $parts);
    }
}
