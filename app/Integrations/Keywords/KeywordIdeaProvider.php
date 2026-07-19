<?php

namespace App\Integrations\Keywords;

use App\Integrations\DataForSeo\KeywordIdea;
use App\Models\Site;

/**
 * Capability-role seam for keyword expansion — the accumulation source of the keyword-first corpus
 * builder. Given a seed term, return related keyword ideas WITH metrics (volume/competition/difficulty),
 * localized to the tenant's geo market for volume accuracy. The default binding is DataForSEO; tests bind
 * a deterministic mock. Only the corpus builder consumes this — scoring/clustering read the persisted
 * corpus rows.
 */
interface KeywordIdeaProvider
{
    /**
     * @return list<KeywordIdea>
     */
    public function ideas(Site $site, string $seed, int $limit): array;
}
