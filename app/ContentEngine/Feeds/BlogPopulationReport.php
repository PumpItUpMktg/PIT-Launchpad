<?php

namespace App\ContentEngine\Feeds;

/**
 * The staged result of a blog-populate run — one count per link in the chain that turns silos into
 * blog candidates. It doubles as the DIAGNOSTIC: the first stage that reads zero is where the empty
 * blog breaks, and {@see diagnosis()} names it in plain language.
 *
 *   keywords → routed to a silo → generated feeds → items fetched → candidates
 *
 * `ingested` is false when only the cheap DB stages ran (rebucket + reconcile) and the HTTP-heavy
 * fetch was deferred to a queued job — so `fetched`/`candidatesCreated` are "not yet", not "zero".
 */
final class BlogPopulationReport
{
    public function __construct(
        public readonly int $keywordsTotal,
        public readonly int $keywordsSiloed,
        public readonly int $rebucketed,
        public readonly int $feedsActive,
        public readonly int $feedsUpserted,
        public readonly bool $ingested,
        public readonly int $fetched,
        public readonly int $candidatesCreated,
        public readonly int $parked,
    ) {}

    /** Is there anything to ingest — i.e. did the chain reach live feeds? */
    public function ready(): bool
    {
        return $this->feedsActive > 0;
    }

    /**
     * The plain-language read on where the chain stands — the first broken link, or the win. This is
     * the answer to "why is my blog empty?".
     */
    public function diagnosis(): string
    {
        if ($this->keywordsTotal === 0) {
            return 'No keywords yet — run “Discover keywords” on the Setup → Silos & keywords step first; the blog builds its news searches from them.';
        }

        if ($this->keywordsSiloed === 0) {
            return 'Keywords exist but none are routed to a silo (the silos have no matching rule_sets) — generate/rebuild the silo structure, then re-file keywords. Nothing can build a feed until a keyword belongs to a silo.';
        }

        if (! $this->ready()) {
            return 'Keywords are routed but no news feeds materialized — check that the site has at least one market.';
        }

        if (! $this->ingested) {
            return "Routed {$this->keywordsSiloed} keyword(s) into silos and built {$this->feedsActive} news feed(s). Fetching news now — candidates will appear here shortly.";
        }

        if ($this->fetched === 0) {
            return "Built {$this->feedsActive} feed(s) but the news source returned no items this run (often transient) — try again shortly.";
        }

        if ($this->candidatesCreated === 0) {
            return "Fetched {$this->fetched} item(s) but none passed relevance/dedup — nothing routed to a silo this run (parked {$this->parked}).";
        }

        return "Created {$this->candidatesCreated} candidate(s) from {$this->fetched} fetched item(s) across {$this->feedsActive} feed(s).";
    }
}
