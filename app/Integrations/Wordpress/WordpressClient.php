<?php

namespace App\Integrations\Wordpress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Authed transport to the companion plugin's `launchpad/v1` REST contract. Auth
 * is the per-site WordPress application password (Basic auth) — passed in,
 * decrypted from §9's vault by the factory, and never logged. Every write is an
 * upsert keyed on the control-plane ULID, so transient-failure retries (5xx /
 * timeout, with backoff) are safe: the same ULID updates rather than duplicates.
 */
class WordpressClient
{
    private const NAMESPACE = '/wp-json/launchpad/v1';

    private const TRIES = 3;

    private const BACKOFF_MS = 200;

    private const TIMEOUT = 20;

    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $appPassword,
    ) {}

    /**
     * Validate the credentials against live WordPress (used by §9's rotation
     * verify-before-revoke). Returns true on an authed 2xx.
     */
    public function ping(): bool
    {
        $response = $this->request()->get(rtrim($this->baseUrl, '/').'/wp-json/wp/v2/users/me');

        return $response->successful();
    }

    /**
     * Upsert a content page/post. Keyed on `content_id` (ULID); idempotent.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upsertContent(array $payload): array
    {
        return $this->post('/content', $payload);
    }

    /**
     * Upsert a silo → WP category. Returns the mapped `wp_category_id`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upsertSilo(array $payload): array
    {
        return $this->post('/silo', $payload);
    }

    /**
     * Upsert redirects (keyed on from_url plugin-side).
     *
     * @param  list<array<string, mixed>>  $redirects
     * @return array<string, mixed>
     */
    public function upsertRedirects(array $redirects): array
    {
        return $this->post('/redirects', ['redirects' => $redirects]);
    }

    /**
     * Import a bound kit template into the site's Theme Builder. Idempotent per
     * kit plugin-side (a re-push updates the same elementor_library template), so
     * it is safe to send on every provision/refresh.
     *
     * @param  array<string, mixed>  $payload  {kit, template:{content,...}, template_type?, title?}
     * @return array<string, mixed>
     */
    public function upsertKitTemplate(array $payload): array
    {
        return $this->post('/kit-template', $payload);
    }

    /**
     * Write the tenant's brand kit (palette + typography) into the site's active
     * Elementor Global Kit, so templates referencing the globals paint the client's
     * brand. Idempotent (overwrites the same system slots).
     *
     * @param  array<string, mixed>  $payload  {colors:{…}, fonts:{…}}
     * @return array<string, mixed>
     */
    public function upsertBrandKit(array $payload): array
    {
        return $this->post('/brand-kit', $payload);
    }

    /**
     * Activate a block-theme theme.json STYLE VARIATION (bold/clean/warm) as the site's global
     * styles — the Gutenberg-pivot brand push. Brand styling lives in theme.json, not a Global Kit.
     *
     * @return array<string, mixed>
     */
    public function activateStyle(string $variation): array
    {
        return $this->post('/style', ['variation' => $variation]);
    }

    /**
     * Activate a DYNAMIC, per-tenant theme.json variation pushed inline (the logo-derived "Your brand
     * colors" — there is no styles/{slug}.json file for it). Same endpoint; the plugin writes the
     * inline variation to global styles directly.
     *
     * @param  array<string, mixed>  $themeJson  the full theme.json variation (settings/styles/title)
     * @return array<string, mixed>
     */
    public function activateStyleVariation(string $slug, array $themeJson): array
    {
        return $this->post('/style', ['variation' => $slug, 'theme_json' => $themeJson]);
    }

    /**
     * Push the per-tenant site PROFILE (brand + NAP + navigation) that the universal header/footer
     * chrome renders — the block-theme template parts can't carry per-tenant NAP statically.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function pushSiteProfile(array $profile): array
    {
        return $this->post('/site-profile', $profile);
    }

    /**
     * Read the companion plugin's environment introspection (WP/PHP/Elementor/
     * theme/plugin versions) through the same authed channel.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $response = $this->request()->get(rtrim($this->baseUrl, '/').self::NAMESPACE.'/status');

        if (! $response->successful()) {
            throw new WordpressException('WordPress /status returned HTTP '.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Read the site's Elementor saved-template inventory (id/title/slug/type/
     * modified/preview/thumbnail) through the same authed channel — the live list
     * the operator maps each kit against.
     *
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        $response = $this->request()->get(rtrim($this->baseUrl, '/').self::NAMESPACE.'/templates');

        if (! $response->successful()) {
            throw new WordpressException('WordPress /templates returned HTTP '.$response->status());
        }

        $json = $response->json();
        $templates = is_array($json) && isset($json['templates']) && is_array($json['templates']) ? $json['templates'] : [];

        return array_values(array_filter($templates, 'is_array'));
    }

    /**
     * Permanently delete a core WordPress post/page by its WP id — `force=true` so it BYPASSES the
     * trash. The REST default trashes, and a trashed post still RESERVES its slug, which would re-slug
     * the next publish to `-2`; force-delete frees the permalink. Core `/wp/v2/{type}` route (not the
     * launchpad/v1 contract), reached through the same Basic-auth channel as {@see ping()}.
     *
     * @param  'pages'|'posts'  $type
     * @return bool true if the post was deleted or already absent (404)
     *
     * @throws WordpressException on a real failure (auth, capability, a blocked DELETE method) — carrying
     *                            the HTTP status + WordPress's own reason so the operator sees WHY, not a
     *                            bare "did not confirm". Callers that batch deletes (reset / site-delete)
     *                            catch this per page; the single-page take-down surfaces the message.
     */
    public function forceDeletePost(string $type, int $id): bool
    {
        $url = rtrim($this->baseUrl, '/')."/wp-json/wp/v2/{$type}/{$id}?force=true";

        $response = $this->request()->delete($url);

        // Already gone is success for cleanup purposes.
        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        // A real failure — surface WHY (status + WordPress's reason): 401 (app password / stripped
        // Authorization header), 403 (the connection user can't delete this post), 405 (a security
        // plugin or host WAF blocking the REST DELETE method), etc.
        throw new WordpressException(
            "WordPress delete of {$type} {$id} returned HTTP ".$response->status().$this->errorDetail($response)
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $response = $this->request()->post(rtrim($this->baseUrl, '/').self::NAMESPACE.$path, $body);

        if (! $response->successful()) {
            throw new WordpressException("WordPress {$path} returned HTTP ".$response->status().$this->errorDetail($response));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * The human-readable reason from a failed response body — the plugin's soft-failure `error`
     * (e.g. "is the Launchpad block theme active?") or WordPress's own `message`. Surfaced so a 422/403
     * carries WHY, not just the status code.
     */
    private function errorDetail(Response $response): string
    {
        $body = $response->json();
        $detail = is_array($body) ? ($body['error'] ?? $body['message'] ?? null) : null;

        return is_string($detail) && trim($detail) !== '' ? ' — '.trim($detail) : '';
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->withBasicAuth($this->username, $this->appPassword)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->retry(self::TRIES, self::BACKOFF_MS, function (\Throwable $e): bool {
                // Retry transient failures only: connection errors and 5xx.
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError());
            }, throw: false);
    }
}
