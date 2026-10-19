<?php

namespace App\Integrations\Backlinks;

use App\Integrations\DataForSeo\DataForSeoClient;

/**
 * DataForSEO Backlinks API adapter: `backlinks/summary/live` for the totals, `backlinks/backlinks/live` in
 * `one_per_domain` mode for the rows. Ranks are requested on the 0-100 scale.
 */
final class DataForSeoBacklinkProvider implements BacklinkProvider
{
    public function __construct(private readonly DataForSeoClient $client) {}

    public function summary(string $target): BacklinkSummary
    {
        $s = $this->client->backlinksSummary($target);

        return new BacklinkSummary(
            backlinks: $s['backlinks'],
            referringDomains: $s['referring_domains'],
            brokenBacklinks: $s['broken_backlinks'],
            rank: $s['rank'],
        );
    }

    public function referringDomains(string $target, int $limit = 1000, ?string $after = null): BacklinkPage
    {
        $page = $this->client->backlinksLive($target, 'one_per_domain', $limit, $after);

        return new BacklinkPage(
            rows: array_map(fn (array $i): BacklinkRow => new BacklinkRow(
                domainFrom: $i['domain_from'],
                urlFrom: $i['url_from'],
                urlTo: $i['url_to'],
                domainFromRank: $i['domain_from_rank'],
                pageFromRank: $i['page_from_rank'],
                spamScore: $i['spam_score'],
                dofollow: $i['dofollow'],
                isBroken: $i['is_broken'],
                anchor: $i['anchor'],
                firstSeen: $i['first_seen'],
                lastSeen: $i['last_seen'],
            ), $page['items']),
            totalCount: $page['total_count'],
            nextToken: $page['search_after_token'],
        );
    }

    public function domainIntersection(array $targets, array $exclude, int $limit = 300): array
    {
        $rows = $this->client->backlinksDomainIntersection($targets, $exclude, $limit);

        return array_map(fn (array $r): IntersectRow => new IntersectRow(
            domain: $r['domain'],
            rank: $r['rank'],
            spamScore: $r['spam_score'],
            linkedTargets: $r['linked_targets'],
            firstSeen: $r['first_seen'],
        ), $rows);
    }
}
