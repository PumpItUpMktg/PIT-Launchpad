<?php

namespace App\Integrations\Wordpress;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;

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
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $response = $this->request()->post(rtrim($this->baseUrl, '/').self::NAMESPACE.$path, $body);

        if (! $response->successful()) {
            throw new WordpressException("WordPress {$path} returned HTTP ".$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
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
