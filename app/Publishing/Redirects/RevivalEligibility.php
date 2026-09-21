<?php

namespace App\Publishing\Redirects;

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Whether a legacy URL is an abandoned ARTICLE — the only thing revival is for.
 *
 * {@see LegacyContentReviver} treats every unresolved URL as an orphaned blog post, because the planner's
 * "unresolved" means only "no confident redirect target". A page is unresolved when Launchpad does not
 * publish something that looks like it, which is a statement about OUR catalogue, not about the page. A
 * Contact page is unresolved for the same reason an abandoned 2019 article is.
 *
 * That is how Sump Pump Gurus' revival plan came to contain `/contact-us`, `/about-us` and `/services`.
 * Applied, each would have become a blog post and 301'd the real page onto it. The three carried 13,857
 * impressions between them and are the pages a customer uses to get in touch.
 *
 * Six kinds, one revivable:
 *
 *  - ARTICLE — revive it.
 *  - CORE PAGE — About, Contact, Privacy. Live, structural, never an article.
 *  - SERVICE PAGE — an old `/services/...` URL. Its successor is a service page, so it belongs in the
 *    redirect path; rewriting it as a blog post loses the commercial intent that earned the ranking.
 *  - TOWN PAGE — a bare town slug matching somewhere the site covers. Belongs to the location tree.
 *  - BRAND QUERY — whatever the path looks like, a URL whose top query IS the brand name is somebody
 *    searching for this business, not for a topic. `/services` → "sump pump gurus" is the tell.
 *  - OPERATOR QUERY — a top query like `site:example.com` is an SEO running a diagnostic, not demand.
 *    `/poconos` ranked for exactly that.
 *
 * Read-only and HTTP-free; the classification comes from the path, the top query and the site's own
 * coverage, never from fetching the page.
 */
class RevivalEligibility
{
    public const ARTICLE = 'article';

    /** @var array<string, string> kind => the sentence explaining why it is not an article */
    public const REASONS = [
        'core_page' => 'a structural page (About / Contact / Services), not an article',
        'service_page' => 'an old service URL — belongs in the redirect path, not a blog post',
        'town_page' => 'a town the site covers — belongs to the location tree',
        'brand_query' => 'ranks for the brand name — someone looking for this business, not a topic',
        'operator_query' => 'ranks for a site: operator — an SEO diagnostic, not demand',
    ];

    /** @var array<string, array<string, true>>|null memoised town slugs per site */
    private ?array $towns = null;

    /** The kind of thing this legacy URL is. */
    public function classify(Site $site, string $path, ?string $topQuery): string
    {
        $slug = trim(mb_strtolower($path), '/');

        if ($this->isOperatorQuery($topQuery)) {
            return 'operator_query';
        }
        if ($this->isBrandQuery($site, $topQuery)) {
            return 'brand_query';
        }
        if ($this->isServicePath($slug)) {
            return 'service_page';
        }
        if (in_array($slug, $this->reserved(), true)) {
            return 'core_page';
        }
        // Only a BARE slug can be a town — `/jenkintown` is a place, `/jenkintown-sump-pump-tips` is an
        // article about one.
        if (! str_contains($slug, '/') && isset($this->townSlugs($site)[$slug])) {
            return 'town_page';
        }

        return self::ARTICLE;
    }

    public function revivable(Site $site, string $path, ?string $topQuery): bool
    {
        return $this->classify($site, $path, $topQuery) === self::ARTICLE;
    }

    private function isOperatorQuery(?string $query): bool
    {
        return is_string($query) && (bool) preg_match('/\b(site|inurl|intitle|cache):/i', $query);
    }

    /**
     * A query that IS the brand, not merely one that shares words with it. "sump pump gurus reviews" is
     * the brand; "sump pump" is the trade and must not match, or every page on the site would qualify.
     */
    private function isBrandQuery(Site $site, ?string $query): bool
    {
        $brand = $this->normalize((string) $site->brand_name);
        $needle = $this->normalize((string) $query);

        return $brand !== '' && $needle !== '' && str_contains($needle, $brand);
    }

    private function isServicePath(string $slug): bool
    {
        foreach ($this->servicePrefixes() as $prefix) {
            $prefix = trim(mb_strtolower($prefix), '/');
            if ($prefix !== '' && ($slug === $prefix || str_starts_with($slug, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slugs of every town the site covers — coverage areas and physical locations both, since a legacy
     * URL may predate either.
     *
     * @return array<string, true>
     */
    private function townSlugs(Site $site): array
    {
        $key = (string) $site->id;
        if (isset($this->towns[$key])) {
            return $this->towns[$key];
        }

        $slugs = [];
        foreach (CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->pluck('name') as $name) {
            $slug = Str::slug((string) $name);
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
        foreach (Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->pluck('name') as $name) {
            $slug = Str::slug((string) $name);
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }

        $this->towns[$key] = $slugs;

        return $slugs;
    }

    /** @return list<string> */
    private function reserved(): array
    {
        $configured = config('launchpad.legacy_revival.reserved_paths', []);

        return is_array($configured) ? array_map(fn ($v): string => trim(mb_strtolower((string) $v), '/'), $configured) : [];
    }

    /** @return list<string> */
    private function servicePrefixes(): array
    {
        $configured = config('launchpad.legacy_revival.service_prefixes', []);

        return is_array($configured) ? array_map(fn ($v): string => (string) $v, $configured) : [];
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($value)));
    }
}
