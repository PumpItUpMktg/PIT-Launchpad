<?php

namespace App\ContentEngine\Feeds;

use App\Enums\FeedOrigin;
use App\Models\Scopes\SiteScope;
use App\Models\Source;

/**
 * Validate-on-add for client direct feeds: confirm the URL is a reachable
 * RSS/Atom feed with items (host-branched fetch — direct feeds never touch the
 * consent wall) and return a preview, or a human error. Enforces a generous
 * per-site soft cap so a client can't add feeds without bound.
 */
class FeedValidator
{
    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly int $softCap = 25,
    ) {}

    public function validate(string $siteId, string $url): FeedPreview
    {
        $url = trim($url);

        if (! $this->looksLikeHttpUrl($url)) {
            return FeedPreview::invalid('Enter a valid http(s) feed URL.');
        }

        if ($this->clientFeedCount($siteId) >= $this->softCap) {
            return FeedPreview::invalid("You've reached the {$this->softCap}-feed limit — remove one before adding another.");
        }

        // Probe with an unsaved Source so the fetcher's host-branching applies.
        $probe = new Source(['url' => $url, 'origin' => FeedOrigin::Client]);
        $result = $this->fetcher->fetch($probe);

        if (! $result->ok()) {
            return FeedPreview::invalid($result->error ?? 'That URL is not a readable RSS/Atom feed.');
        }

        if ($result->items === []) {
            return FeedPreview::invalid('That feed is reachable but has no items right now — double-check the URL.');
        }

        return FeedPreview::ok(
            publisher: $result->items[0]->sourceName,
            samples: array_slice(array_map(fn ($i) => $i->title, $result->items), 0, 5),
        );
    }

    public function softCap(): int
    {
        return $this->softCap;
    }

    public function atCap(string $siteId): bool
    {
        return $this->clientFeedCount($siteId) >= $this->softCap;
    }

    private function clientFeedCount(string $siteId): int
    {
        return Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('origin', FeedOrigin::Client->value)
            ->count();
    }

    private function looksLikeHttpUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
