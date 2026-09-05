<?php

namespace App\Integrations\UrlInspection;

use App\Enums\IndexCoverageState;
use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\SearchConsole\GoogleSearchConsole;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The real {@see IndexInspector} — bridges the shared platform Google grant onto the URL Inspection API
 * (`urlInspection.index.inspect`). A tenant is "connected" when the one grant is live AND the Site has a
 * GSC property picked (same rule as {@see GoogleSearchConsole}).
 *
 * Cost discipline: URL Inspection is 2,000/day + 600/min per property and one call PER url. So a result
 * is cached for {@see $cacheTtl} (index status moves ~daily), and a per-property per-day counter caps live
 * inspections at {@see $dailyCap} (kept under the hard quota) — over the cap, {@see inspect()} returns null
 * so the audit degrades gracefully instead of erroring. {@see cached()} never calls the API.
 */
final class GoogleIndexInspector implements IndexInspector
{
    /**
     * TIERED TTL: a confirmed-indexed (PASS) verdict rarely flips, so it's cached long ($cacheTtl, 14d)
     * — re-confirming hundreds of PASS pages every few days is where the budget was wasted. A non-PASS
     * verdict (a newly-published page Google hasn't crawled yet reads "not indexed") is the answer that
     * is actively changing, so it's cached briefly ($pendingTtl, 3d) and re-checked soon — otherwise the
     * panel would report a page as not-indexed for two weeks after it was actually indexed.
     */
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly int $cacheTtl = 1209600,
        private readonly int $dailyCap = 1800,
        private readonly int $pendingTtl = 259200,
    ) {}

    public function connected(Site $site): bool
    {
        $account = $this->connections->account();

        return $account !== null
            && ! $account->needsReconnect()
            && is_string($site->gsc_property)
            && $site->gsc_property !== '';
    }

    public function cached(Site $site, string $url): ?IndexStatus
    {
        if (! is_string($site->gsc_property) || $site->gsc_property === '') {
            return null;
        }

        $data = $this->cache->get($this->key((string) $site->gsc_property, $url));

        return is_array($data) ? IndexStatus::fromArray($data) : null;
    }

    public function inspect(Site $site, string $url): ?IndexStatus
    {
        if (! $this->connected($site)) {
            return null;
        }

        $property = (string) $site->gsc_property;

        $cached = $this->cached($site, $url);
        if ($cached !== null) {
            return $cached;
        }

        if (! $this->underDailyCap($property)) {
            return null; // quota guard — the audit reports these as "not inspected (quota)"
        }

        $status = $this->fetch($property, $url);
        if ($status === null) {
            return null; // transient error — not cached, so a later audit retries
        }

        // Tiered: a confirmed PASS holds for $cacheTtl; anything not-yet-indexed is re-checked in $pendingTtl.
        $ttl = $status->indexed() ? $this->cacheTtl : $this->pendingTtl;
        $this->cache->put($this->key($property, $url), $status->toArray(), $ttl);
        $this->bumpDailyCount($property);

        return $status;
    }

    private function fetch(string $property, string $url): ?IndexStatus
    {
        $account = $this->connections->account();
        if ($account === null) {
            return null;
        }

        try {
            $json = $this->connections->request(
                $account,
                'post',
                rtrim($this->baseUrl, '/').'/urlInspection/index:inspect',
                ['json' => [
                    'inspectionUrl' => $url,
                    'siteUrl' => $property,
                    'languageCode' => 'en-US',
                ]],
            );
        } catch (Throwable) {
            // ANY per-URL failure degrades to null (that URL → "not inspected"), never aborts the batch —
            // URL Inspection is slow and a cURL timeout surfaces as a ConnectionException, NOT a
            // GoogleException, so this must catch broadly. Not cached, so a later run retries it.
            return null;
        }

        $r = $json['inspectionResult']['indexStatusResult'] ?? null;
        if (! is_array($r)) {
            return null;
        }

        $coverage = (string) ($r['coverageState'] ?? '');
        $verdict = isset($r['verdict']) ? (string) $r['verdict'] : null;

        return new IndexStatus(
            url: $url,
            state: IndexCoverageState::fromInspection($coverage, $verdict),
            coverageState: $coverage,
            verdict: $verdict,
            googleCanonical: isset($r['googleCanonical']) ? (string) $r['googleCanonical'] : null,
            userCanonical: isset($r['userCanonical']) ? (string) $r['userCanonical'] : null,
            lastCrawledAt: $this->parseTime($r['lastCrawlTime'] ?? null),
        );
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function underDailyCap(string $property): bool
    {
        return (int) $this->cache->get($this->countKey($property), 0) < $this->dailyCap;
    }

    private function bumpDailyCount(string $property): void
    {
        $key = $this->countKey($property);
        $this->cache->put($key, (int) $this->cache->get($key, 0) + 1, now()->endOfDay());
    }

    private function key(string $property, string $url): string
    {
        return 'gsc:inspect:'.md5($property.'|'.rtrim($url, '/'));
    }

    private function countKey(string $property): string
    {
        return 'gsc:inspect:count:'.md5($property).':'.now()->format('Ymd');
    }
}
