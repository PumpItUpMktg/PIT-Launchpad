<?php

namespace App\Integrations\SearchConsole;

use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Models\Site;

/**
 * Submits a site's sitemap to Google Search Console (the Sitemaps API) so Google discovers and indexes
 * its pages — and reads back the submitted/pending state. Uses the shared platform Google grant
 * (PR-A) + the site's picked `gsc_property`; the sitemap lives on the site's own domain (the companion
 * plugin serves it at /sitemap.xml). No new credentials, no per-page cost — one submit tells Google to
 * crawl the whole set. `submitted` counts come straight from GSC; a per-page "in Google" signal is the
 * card's impressions badge (a page with Search impressions is definitely indexed).
 */
class SitemapSubmitter
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly string $baseUrl,
        private readonly string $sitemapPath,
    ) {}

    /** A tenant can submit when the shared grant is live and this Site has a GSC property picked. */
    public function connected(Site $site): bool
    {
        $account = $this->connections->account();

        return $account !== null
            && ! $account->needsReconnect()
            && is_string($site->gsc_property)
            && $site->gsc_property !== '';
    }

    /** The full sitemap URL on the site's own domain, or null when the site has no domain yet. */
    public function sitemapUrl(Site $site): ?string
    {
        $base = rtrim((string) $site->domain_url, '/');

        return $base === '' ? null : $base.'/'.ltrim($this->sitemapPath, '/');
    }

    /**
     * Submit the sitemap to Search Console (idempotent — re-submitting just refreshes it), then return
     * the current status.
     *
     * @return array{ok: bool, reason: ?string, sitemap: ?string, connected: bool, submitted: int, pending: bool, errors: int, warnings: int}
     */
    public function submit(Site $site): array
    {
        $sitemap = $this->sitemapUrl($site);

        if (! $this->connected($site)) {
            return $this->fail('not_connected', $sitemap);
        }
        if ($sitemap === null) {
            return $this->fail('no_domain', null);
        }

        $account = $this->connections->account();
        try {
            $this->connections->request(
                $account,
                'put',
                $this->endpoint($site).'/'.rawurlencode($sitemap),
            );
        } catch (GoogleException $e) {
            return $this->fail($e->getMessage(), $sitemap);
        }

        return ['ok' => true, 'reason' => null, 'sitemap' => $sitemap] + $this->status($site);
    }

    /**
     * The submitted sitemaps for the site + aggregate submitted-URL count (the Sitemaps API's own
     * numbers). Empty/zero when disconnected or the API is unavailable — never throws into a surface.
     *
     * @return array{connected: bool, submitted: int, pending: bool, errors: int, warnings: int, sitemaps: list<array{path: string, last_submitted: ?string, is_pending: bool, submitted: int, errors: int, warnings: int}>}
     */
    public function status(Site $site): array
    {
        $empty = ['connected' => false, 'submitted' => 0, 'pending' => false, 'errors' => 0, 'warnings' => 0, 'sitemaps' => []];

        if (! $this->connected($site)) {
            return $empty;
        }

        $account = $this->connections->account();
        try {
            $json = $this->connections->request($account, 'get', $this->endpoint($site));
        } catch (GoogleException) {
            return ['connected' => true] + array_slice($empty, 1);
        }

        $rows = [];
        $submitted = 0;
        $errors = 0;
        $warnings = 0;
        $pending = false;

        foreach ((array) ($json['sitemap'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entrySubmitted = 0;
            foreach ((array) ($entry['contents'] ?? []) as $content) {
                if (is_array($content)) {
                    $entrySubmitted += (int) ($content['submitted'] ?? 0);
                }
            }

            $entryErrors = (int) ($entry['errors'] ?? 0);
            $entryWarnings = (int) ($entry['warnings'] ?? 0);
            $entryPending = (bool) ($entry['isPending'] ?? false);

            $submitted += $entrySubmitted;
            $errors += $entryErrors;
            $warnings += $entryWarnings;
            $pending = $pending || $entryPending;

            $rows[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'last_submitted' => isset($entry['lastSubmitted']) ? (string) $entry['lastSubmitted'] : null,
                'is_pending' => $entryPending,
                'submitted' => $entrySubmitted,
                'errors' => $entryErrors,
                'warnings' => $entryWarnings,
            ];
        }

        return ['connected' => true, 'submitted' => $submitted, 'pending' => $pending, 'errors' => $errors, 'warnings' => $warnings, 'sitemaps' => $rows];
    }

    private function endpoint(Site $site): string
    {
        return rtrim($this->baseUrl, '/').'/sites/'.rawurlencode((string) $site->gsc_property).'/sitemaps';
    }

    /**
     * @return array{ok: bool, reason: ?string, sitemap: ?string, connected: bool, submitted: int, pending: bool, errors: int, warnings: int}
     */
    private function fail(string $reason, ?string $sitemap): array
    {
        return ['ok' => false, 'reason' => $reason, 'sitemap' => $sitemap, 'connected' => false, 'submitted' => 0, 'pending' => false, 'errors' => 0, 'warnings' => 0];
    }
}
