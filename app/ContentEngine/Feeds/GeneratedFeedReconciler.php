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
 * keyword map × the site's markets. One feed per (routable keyword × market).
 *
 * Idempotent on TWO keys, which is what stops the regeneration that ballooned the
 * feed table (11k+ on SPG, most producing nothing):
 *   - on the (keyword, market) SIGNATURE (derived_from), so re-running never
 *     duplicates a combo; and
 *   - on the resulting URL — two different signatures whose query resolves to the
 *     SAME Google-News search collapse to ONE enabled feed (a partial unique index
 *     on (site_id, url) WHERE enabled backs this). Deactivation runs BEFORE the
 *     upsert so the live set never transiently collides with a stale duplicate.
 *
 * HELD markets are excluded via the market's own hold flag (Market.on_hold): such
 * a market generates no feeds and its existing feeds deactivate on the next run.
 * It does NOT key off a held Location (Location.publish_held): the generator runs
 * on the Market model, which has no FK to Location, and matching the two would need
 * a fragile city/state name match (the mechanism behind the Trooper/Montgomery +
 * Spring City mis-assignments) — deliberately not added; see the Territory
 * naming/model mismatch in docs/specs/5-nav-cutover.md. Retirement is always
 * DEACTIVATION, never deletion, so already-attributed candidates keep their
 * provenance. Keywords are geo-neutral by §4 rule; the market only enters the news
 * SEARCH query (not a silo or page).
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
     * @return array{upserted: int, deactivated: int, held_markets_skipped: int, url_duplicates_skipped: int}
     */
    public function reconcile(Site $site): array
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('silo_id')
            ->get();

        // Exclude HELD markets — keyed on the market's OWN hold flag (Market.on_hold). The generator runs on
        // the Market model, which has NO FK to Location, so it CANNOT key off a held Location's
        // publish_held directly; matching a Market to a publish-held Location would require a city/state name
        // match — the same fragile mechanism behind the Trooper/Montgomery + Spring City mis-assignments —
        // which we are deliberately NOT adding. See docs/specs/5-nav-cutover.md (Territory naming/model
        // mismatch): to suppress a held *Location's* feeds today, set on_hold on its Market.
        $allMarkets = Market::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        $activeMarkets = $allMarkets->reject(fn (Market $m): bool => (bool) $m->on_hold)->values()->all();
        $heldSkipped = $allMarkets->count() - count($activeMarkets);

        // A site with NO markets at all → one national feed per keyword (market = null). But a site whose
        // markets are ALL held generates nothing — a held market must not silently become a national feed.
        $marketOptions = $allMarkets->isEmpty() ? [null] : $activeMarkets;

        // PASS 1: compute the live set, de-duping by URL within the run so two signatures whose query
        // resolves to the same Google-News search never both hold an enabled feed. No DB writes yet.
        /** @var array<string, array{keyword: Keyword, market: ?Market, url: string}> $live */
        $live = [];
        $claimedUrls = [];
        $urlDupsSkipped = 0;
        foreach ($keywords as $keyword) {
            foreach ($marketOptions as $market) {
                $url = $this->feedUrl($keyword, $market);
                if (isset($claimedUrls[$url])) {
                    $urlDupsSkipped++;

                    continue; // another signature already owns this exact search — one feed is enough
                }
                $claimedUrls[$url] = true;
                $live[$this->signature($keyword, $market)] = ['keyword' => $keyword, 'market' => $market, 'url' => $url];
            }
        }

        // Deactivate stale BEFORE upserting, so a to-be-retired duplicate never transiently shares an
        // enabled URL with a live feed (which the partial unique index would reject).
        $deactivated = $this->deactivateStale($site->id, array_keys($live));

        // PASS 2: upsert the live set. Keyed on (site_id, derived_from); the live URLs are already distinct.
        foreach ($live as $signature => $spec) {
            Source::withoutGlobalScope(SiteScope::class)->updateOrCreate(
                ['site_id' => $site->id, 'derived_from' => $signature],
                [
                    'origin' => FeedOrigin::Generated->value,
                    'type' => SourceType::RssFeed->value,
                    'silo_id' => $spec['keyword']->silo_id,
                    'url' => $spec['url'],
                    'label' => $this->label($spec['keyword'], $spec['market']),
                    'enabled' => true,
                ],
            );
        }

        return [
            'upserted' => count($live),
            'deactivated' => $deactivated,
            'held_markets_skipped' => $heldSkipped,
            'url_duplicates_skipped' => $urlDupsSkipped,
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
