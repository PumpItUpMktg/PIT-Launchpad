<?php

namespace App\Integrations\Keywords;

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocations;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Throwable;

/**
 * The production keyword-idea source — DataForSEO's related_keywords Labs endpoint (metrics preserved),
 * localized to the tenant's priority market for volume accuracy. Volumes are localized; the terms
 * themselves stay geo-neutral (the corpus builder filters geo-modified terms downstream, per the §4
 * hard rule). Reuses the client's 7-day cache, so a re-accumulation of the same seeds is cheap.
 */
final class DataForSeoKeywordIdeaProvider implements KeywordIdeaProvider
{
    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly DataForSeoLocations $locations,
        private readonly int $defaultLocationCode,
        private readonly string $language,
    ) {}

    public function ideas(Site $site, string $seed, int $limit): array
    {
        return $this->client->relatedKeywordsWithMetrics($seed, $this->locationFor($site), $this->language, $limit);
    }

    /**
     * The tenant's DataForSEO location_code — resolved from the priority market (name, else region);
     * falls back to the configured default so a tenant with no resolvable market still accumulates
     * (national volumes) rather than spending on a zero.
     */
    private function locationFor(Site $site): int
    {
        $market = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByRaw("CASE WHEN tier = 'priority' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->first();

        if ($market === null) {
            return $this->defaultLocationCode;
        }

        foreach ([trim((string) $market->name), trim((string) $market->region)] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            try {
                $code = $this->locations->resolve($candidate);
            } catch (Throwable) {
                $code = null;
            }
            if ($code !== null) {
                return $code;
            }
        }

        return $this->defaultLocationCode;
    }
}
