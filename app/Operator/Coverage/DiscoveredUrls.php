<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Job;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Redirects\GscUrlInventory;
use App\Support\PublicUrl;

/**
 * The URLs Google holds on a property that Launchpad did not publish — the half of Search Console's
 * "All known pages" that is not ours. Legacy posts from before Launchpad, the numbered twins WordPress
 * minted when a title was published more than once, category / tag / date archives, pagination.
 *
 * Google exposes no API for its known-URL list, so this is built from the one place Google does tell us
 * about a URL: the retained search-analytics series ({@see GscUrlInventory}) — every URL that has earned
 * an impression. An indexed archive nobody has ever reached is invisible here and still counts in Search
 * Console; the surfaces that show this say so.
 *
 * ONE definition of "ours" — published content pages AND published job pages, both in the sitemap — so
 * the all-known capture and the unmanaged-URL report cannot disagree about what is unmanaged.
 */
class DiscoveredUrls
{
    public function __construct(private readonly GscUrlInventory $inventory) {}

    /**
     * Normalized paths of every URL Launchpad publishes for the site (content + jobs), as a lookup set.
     *
     * @return array<string, true>
     */
    public function ours(Site $site): array
    {
        $ours = [];

        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'slug', 'kind', 'page_type']);
        foreach ($published as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url !== null) {
                $ours[UrlNormalizer::path($url)] = true;
            }
        }

        $jobs = Job::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('status', JobStatus::Published->value)
            ->with(['jobTypes', 'city'])
            ->get();
        foreach ($jobs as $job) {
            $ours[UrlNormalizer::path($job->publicPath())] = true;
        }

        return $ours;
    }

    /**
     * Every URL Google has shown for the site that is not ours — most impressions first, one entry per
     * normalized URL. The raw GSC URL string is returned (that is the canonical as Google holds it, and the
     * URL to inspect).
     *
     * @return list<string>
     */
    public function urls(Site $site): array
    {
        $ours = $this->ours($site);
        $seen = [];
        $out = [];

        foreach ($this->inventory->urlTotals($site) as $row) {
            $url = $row['url'];
            if (isset($ours[UrlNormalizer::path($url)])) {
                continue;
            }
            $key = UrlNormalizer::url($url);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $url;
        }

        return $out;
    }
}
