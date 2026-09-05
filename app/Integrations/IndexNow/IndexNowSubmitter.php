<?php

namespace App\Integrations\IndexNow;

use App\Enums\ContentStatus;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\LinkPlanCommitter;
use App\Support\PublicUrl;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Submits published URLs to IndexNow — the instant "please crawl" ping honoured by Bing, Yandex,
 * Seznam and Naver (one endpoint fans out to all participants; Google does NOT participate). Free.
 *
 * The protocol needs a key file served on the site's own domain to prove ownership: the control plane
 * OWNS the key ({@see Site::$indexnow_key}, minted on first use) and DEPLOYS it to the companion
 * plugin (which serves it at /{key}.txt). Once deployed, a submit is a single POST — no per-request
 * auth. A page going live pings just its URL; the operator can submit the whole site. Every failure is
 * returned (never thrown into the publish path), so a stale plugin or an unreachable host never breaks
 * publishing.
 */
class IndexNowSubmitter
{
    private const MAX_URLS = 10000; // IndexNow's per-request ceiling.

    public function __construct(
        private readonly Http $http,
        private readonly WordpressClientFactory $wp,
        private readonly string $endpoint,
        private readonly bool $enabled,
        private readonly int $timeout,
    ) {}

    /**
     * Submit an explicit URL list.
     *
     * PUBLISH-HOLD: this method does NOT filter held-location URLs — it only has raw strings, and
     * reverse-matching a URL back to a Content to resolve its location is fail-open (a trailing-slash
     * mismatch would silently let a held page through). **Callers MUST exclude held pages via the FK,
     * before building URLs.** Current callers, all of which do: {@see submitSite()} (via the
     * `Content::whereNotPublishHeld()` query scope); {@see submitUrl()} → the on-publish `PingIndexNow`
     * ping, which is Gate-1-covered (a held page never reaches publish's ping); and
     * {@see LinkPlanCommitter::apply()} (skips targets where `Content::isPublishHeld()`).
     * A new caller must filter the same way. (A structurally-safe `iterable<Content>` signature is viable
     * as a future refactor — every caller holds Content — but is out of this method's current contract.)
     *
     * @param  list<string>  $urls
     * @return array{ok: bool, submitted: int, status: ?int, reason: ?string}
     */
    public function submit(Site $site, array $urls, bool $redeploy = false): array
    {
        if (! $this->enabled) {
            return $this->fail('disabled');
        }

        $urls = array_values(array_unique(array_filter($urls, fn (string $u): bool => $u !== '')));
        if ($urls === []) {
            return $this->fail('no_urls');
        }

        $ctx = $this->ensureKey($site, $redeploy);
        if ($ctx === null) {
            return $this->fail('no_key');
        }

        $urls = array_slice($urls, 0, self::MAX_URLS);

        try {
            $response = $this->http->timeout($this->timeout)->post($this->endpoint, [
                'host' => $ctx['host'],
                'key' => $ctx['key'],
                'keyLocation' => $ctx['keyLocation'],
                'urlList' => $urls,
            ]);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        // IndexNow: 200 (OK) / 202 (accepted). 403 = key invalid/not found; 422 = URL/host mismatch;
        // 429 = too many requests.
        $ok = in_array($response->status(), [200, 202], true);

        return [
            'ok' => $ok,
            'submitted' => $ok ? count($urls) : 0,
            'status' => $response->status(),
            'reason' => $ok ? null : $this->statusReason($response->status()),
        ];
    }

    /** Ping a single URL (the auto-on-publish path). */
    public function submitUrl(Site $site, string $url): array
    {
        return $this->submit($site, [$url]);
    }

    /** Submit every published page + post on the site (re-deploys the key first, so it's a clean full push). */
    public function submitSite(Site $site): array
    {
        $home = rtrim((string) $site->domain_url, '/');
        if ($home === '') {
            return $this->fail('no_domain');
        }

        $published = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->whereNotPublishHeld() // publish-hold: never announce a held location's URLs (defers discovery)
            ->get(['id', 'slug', 'page_type']);

        // Canonical URL per content via PublicUrl — a home page resolves to the site root, NOT /home/
        // (which 301s to /), so we never announce a redirecting URL to IndexNow.
        $urls = $published
            ->map(fn (Content $c): ?string => PublicUrl::forContent($site->domain_url, $c))
            ->filter()
            ->values()
            ->all();

        // The homepage root, in case no home Content row carries it (submit() dedupes any overlap).
        array_unshift($urls, $home.'/');

        $result = $this->submit($site, $urls, redeploy: true);

        // Stamp the submission so the live cards can show a "Submitted to Bing" pill for each page.
        if ($result['ok'] && $published->isNotEmpty()) {
            Content::withoutGlobalScope(SiteScope::class)
                ->whereIn('id', $published->pluck('id')->all())
                ->update(['indexnow_submitted_at' => now()]);
        }

        return $result;
    }

    /**
     * Ensure the site has a key AND the plugin is serving it. Mints one on first use and deploys it to
     * the companion plugin; on `redeploy` it re-pushes an existing key too. Returns null (cannot submit)
     * when the site has no host, or a brand-new key could not be deployed to the plugin.
     *
     * @return array{key: string, host: string, keyLocation: string}|null
     */
    public function ensureKey(Site $site, bool $redeploy = false): ?array
    {
        $host = $this->host($site->domain_url);
        if ($host === null) {
            return null;
        }

        $key = is_string($site->indexnow_key) && $site->indexnow_key !== '' ? $site->indexnow_key : null;

        if ($key === null || $redeploy) {
            $candidate = $key ?? bin2hex(random_bytes(16));
            try {
                $this->wp->forSite($site)->pushIndexNowKey($candidate);
            } catch (Throwable) {
                if ($key === null) {
                    return null; // never deployed → the key file won't exist, so IndexNow would 403.
                }
                $candidate = $key; // keep the already-deployed key; a transient re-push failure is fine.
            }

            if ($site->indexnow_key !== $candidate) {
                $site->forceFill(['indexnow_key' => $candidate])->save();
            }
            $key = $candidate;
        }

        return [
            'key' => $key,
            'host' => $host,
            'keyLocation' => rtrim((string) $site->domain_url, '/').'/'.$key.'.txt',
        ];
    }

    private function host(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function statusReason(int $status): string
    {
        return match ($status) {
            403 => 'IndexNow rejected the key — update the companion plugin so it serves /{key}.txt, then retry.',
            422 => 'IndexNow rejected the URLs (host/key mismatch) — the site domain and the key file must be on the same host.',
            429 => 'IndexNow is rate-limiting — try again later.',
            default => "IndexNow returned HTTP {$status}.",
        };
    }

    /**
     * @return array{ok: bool, submitted: int, status: ?int, reason: string}
     */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'submitted' => 0, 'status' => null, 'reason' => $reason];
    }
}
