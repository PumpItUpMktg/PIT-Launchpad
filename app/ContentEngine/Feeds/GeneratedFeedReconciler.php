<?php

namespace App\ContentEngine\Feeds;

use App\Enums\FeedOrigin;
use App\Enums\SourceType;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Source;

/**
 * Keeps the generated feeds current as a materialized projection of the §5
 * keyword map × the site's markets. One feed per (routable keyword × market) —
 * idempotent on a (keyword, market) signature, so re-running never duplicates.
 *
 * Retirement is DEACTIVATION, never deletion: a feed whose source pair is gone is
 * flipped enabled=false so already-attributed candidates keep their provenance.
 * Keywords are geo-neutral by §4 rule; the market only enters the news SEARCH
 * query (not a silo or page), which is what makes per-market feeds distinct and
 * locally relevant.
 */
class GeneratedFeedReconciler
{
    public function __construct(
        private readonly string $baseUrl = 'https://news.google.com',
        private readonly string $hl = 'en-US',
        private readonly string $gl = 'US',
        private readonly string $ceid = 'US:en',
    ) {}

    /**
     * @return array{upserted: int, deactivated: int}
     */
    public function reconcile(Site $site): array
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('silo_id')
            ->get();

        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->all();

        // No markets yet → one national feed per keyword (market = null).
        $marketOptions = $markets !== [] ? $markets : [null];

        $live = [];
        foreach ($keywords as $keyword) {
            foreach ($marketOptions as $market) {
                $signature = $this->signature($keyword, $market);
                $live[$signature] = true;

                Source::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                    ['site_id' => $site->id, 'derived_from' => $signature],
                    [
                        'origin' => FeedOrigin::Generated->value,
                        'type' => SourceType::RssFeed->value,
                        'silo_id' => $keyword->silo_id,
                        'url' => $this->feedUrl($keyword, $market),
                        'label' => $this->label($keyword, $market),
                        'enabled' => true,
                    ],
                );
            }
        }

        return [
            'upserted' => count($live),
            'deactivated' => $this->deactivateStale($site->id, array_keys($live)),
        ];
    }

    private function signature(Keyword $keyword, ?Market $market): string
    {
        return 'kw:'.$keyword->id.':mkt:'.($market !== null ? $market->id : 'national');
    }

    private function feedUrl(Keyword $keyword, ?Market $market): string
    {
        $query = trim($keyword->query.($market !== null ? ' '.$this->marketLabel($market) : ''));

        return rtrim($this->baseUrl, '/').'/rss/search?'.http_build_query([
            'q' => $query,
            'hl' => $this->hl,
            'gl' => $this->gl,
            'ceid' => $this->ceid,
        ]);
    }

    private function label(Keyword $keyword, ?Market $market): string
    {
        return $keyword->query.($market !== null ? ' · '.$this->marketLabel($market) : '').' (Google News)';
    }

    /**
     * "Austin TX" — city plus state abbreviation, so the news query and panel
     * label disambiguate same-named cities and read naturally.
     */
    private function marketLabel(Market $market): string
    {
        $region = is_string($market->region) ? trim($market->region) : '';

        return trim($market->name.($region !== '' ? ' '.$region : ''));
    }

    /**
     * @param  list<string>  $liveSignatures
     */
    private function deactivateStale(string $siteId, array $liveSignatures): int
    {
        $query = Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('origin', FeedOrigin::Generated->value)
            ->where('enabled', true);

        if ($liveSignatures !== []) {
            $query->whereNotIn('derived_from', $liveSignatures);
        }

        return $query->update(['enabled' => false]);
    }
}
