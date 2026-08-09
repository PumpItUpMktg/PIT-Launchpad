<?php

namespace App\Operator\Controls;

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;

/**
 * Establishes a per-site manual WordPress app-password connection — the §1
 * Connection entry was OAuth-oriented (Google), so this is the manual path that
 * pairs with the launch orchestrator. Verify-before-store: the credential is
 * pinged against live WordPress (wp/v2/users/me) and only persisted on a 2xx, so
 * a broken credential never lands in the vault. A freshly verified manual entry
 * is stored clean (compromised=false, last_rotated_at=now) so it passes §9's
 * launch gate. Idempotent on (site, provider).
 */
class WordpressConnector
{
    public function __construct(
        private readonly WordpressClientFactory $factory,
    ) {}

    /**
     * @param  array{base_url: string, username: string, app_password: string}  $input
     *
     * @throws WordpressException when the credential does not authenticate.
     */
    public function connect(string $siteId, array $input): Connection
    {
        $credentials = $this->normalize($input);
        $site = Site::query()->find($siteId);
        $client = $this->factory->usingCredentials($credentials, $site);

        $result = $client->pingResult();
        if (! $result['ok']) {
            // A 401 is ambiguous — header stripped in transit vs a bad Application Password. Ask the
            // plugin what it received so the message can be exact.
            $diag = $result['status'] === 401 ? $client->authCheck() : null;
            throw new WordpressException($this->explainFailure($credentials['base_url'], $result, $diag));
        }

        // Store the POST-safe canonical URL if the entered one redirects — otherwise every write (POST)
        // is downgraded to GET by the 301/302 and 404s at WordPress even though connect (GET) worked.
        $canonical = $client->canonicalBaseUrl();
        if ($canonical !== null && $canonical !== '' && rtrim($canonical, '/') !== rtrim($credentials['base_url'], '/')) {
            $credentials['base_url'] = rtrim($canonical, '/');
        }

        return Connection::withoutGlobalScope(SiteScope::class)->updateOrCreate(
            ['site_id' => $siteId, 'provider' => ConnectionProvider::WpAppPassword->value],
            [
                'credentials' => $credentials,
                'status' => 'active',
                'compromised' => false,
                'compromised_reason' => null,
                'exposed_at' => null,
                'last_rotated_at' => now(),
            ],
        );
    }

    /**
     * Verify-only — ping the credentials against live WordPress WITHOUT persisting
     * anything (no Site needed). Backs the wizard's "Test connection" button so the
     * operator confirms green in the panel before finishing the create. An
     * unreachable host (DNS/timeout) is a failed test, not an error.
     *
     * @param  array{base_url: string, username: string, app_password: string}  $input
     */
    public function verify(array $input): bool
    {
        try {
            return $this->factory->usingCredentials($this->normalize($input), null)->ping();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Re-verify a STORED connection's live auth and reconcile the chip to reality (report fix 3B). Pings
     * the site's saved app password against the push-capable `launchpad/v1/status`; on failure the
     * connection is marked `compromised` (so the green "verified" chip flips red the moment the credential
     * is revoked — the §9 launch/publish gates then catch it) instead of failing silently at push time.
     * A previously-compromised connection that now passes is left for an explicit rotation to clear.
     * Non-WordPress providers verify permissively. Returns the live result.
     */
    public function reverify(Connection $connection): bool
    {
        if ($connection->provider !== ConnectionProvider::WpAppPassword) {
            return true;
        }

        $site = Site::query()->find($connection->site_id);
        try {
            $ok = $site !== null && $this->factory->forSite($site)->ping();
        } catch (ConnectionException|WordpressException) {
            $ok = false;
        }

        if (! $ok && ! $connection->compromised) {
            $connection->markCompromised('WordPress rejected the stored app password on re-verify (health check failed).')->save();
        }

        return $ok;
    }

    /**
     * Turn a failed status ping into an operator-actionable reason instead of one opaque "did not
     * authenticate", keyed on WordPress's own semantics: a REST permission failure returns 401 when
     * the request is NOT authenticated (bad password / username, or the host stripped the
     * Authorization header) and 403 when it IS authenticated but the user lacks the capability. A 404
     * means the plugin route isn't there (wrong host / plugin off); 0 means the host was unreachable.
     *
     * @param  array{ok: bool, status: int, error: ?string}  $result
     * @param  array<string, mixed>|null  $diag  the companion plugin's auth-check payload (401 only)
     */
    private function explainFailure(string $baseUrl, array $result, ?array $diag = null): string
    {
        $endpoint = $baseUrl.'/wp-json/launchpad/v1/status';
        $tail = ' — nothing was saved.';

        return match ($result['status']) {
            0 => "Could not reach {$baseUrl} — check the URL and that the site is live (DNS / timeout)".$tail,
            404 => "The Launchpad companion plugin isn't answering at {$endpoint} (HTTP 404) — confirm the plugin is active on THIS host and the URL matches WordPress's Site Address (Settings → General)".$tail,
            403 => "HTTP 403 on the plugin route — TWO likely causes, check this order: (1) a CDN / edge firewall is blocking the request BEFORE it reaches WordPress — most often Cloudflare Bot Fight Mode or a WAF managed rule. If the site is behind Cloudflare, allow /wp-json/launchpad/* (a WAF 'Skip' rule for that path, or turn off Bot Fight Mode), then retry. (2) the connecting user lacks the lp_manage_content capability — connect as the launchpad-sync user (re-activating the companion plugin re-grants it after a host/domain migration)".$tail,
            401 => $this->explain401($diag).$tail,
            default => "WordPress returned HTTP {$result['status']} at {$endpoint}".($result['error'] !== null ? ' — '.$result['error'] : '').$tail,
        };
    }

    /**
     * Turn a 401 into the RIGHT next action using the plugin's auth-check payload, which resolves the
     * two causes that look identical from the control plane's side of the wire.
     *
     * @param  array<string, mixed>|null  $diag
     */
    private function explain401(?array $diag): string
    {
        // No diagnostic: an older companion plugin (no /auth-check route → 404 → null) or the probe was
        // itself blocked. Keep the both-causes guidance and point at the plugin update for a precise read.
        if ($diag === null) {
            return 'WordPress rejected the request (HTTP 401 — not authenticated). Two causes look identical here: either the Authorization header was stripped before WordPress (common behind Cloudflare, and on nginx / FastCGI / some managed hosts — it must be forwarded for Application Passwords to work), or the Application Password itself is wrong. Update the companion plugin to 0.9.32+ for an exact diagnosis, then retry';
        }

        // The header never arrived → it is being stripped in transit. This is NOT a bad password.
        if (($diag['authorization_received'] ?? true) === false) {
            return 'WordPress never received the Authorization header — it is being STRIPPED in transit, so WordPress saw an anonymous request (HTTP 401). This is not a password problem. If the site is behind Cloudflare, add a WAF "Skip" rule for /wp-json/launchpad/* (Security → WAF → Custom rules → Skip → all remaining custom + managed rules); on nginx/FastCGI forward it with `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`, or on Apache add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`. Then retry';
        }

        // Header arrived → WordPress genuinely rejected the credential.
        if (($diag['application_passwords_available'] ?? true) === false) {
            return 'The Authorization header reached WordPress, but Application Passwords are DISABLED there (HTTP 401) — they require the site to be served over HTTPS and can be switched off by a security plugin or filter. Confirm the Site Address is https://, re-enable Application Passwords, regenerate the password, and retry';
        }

        $who = isset($diag['username']) && (string) $diag['username'] !== '' ? ' for user "'.$diag['username'].'"' : '';

        return 'The Authorization header reached WordPress'.$who.', but the Application Password was rejected (HTTP 401). Regenerate the Application Password (Users → the connecting user → Application Passwords — this is NOT the login password), confirm the username matches the user it was created for, and retry';
    }

    /**
     * @param  array{base_url: string, username: string, app_password: string}  $input
     * @return array{base_url: string, username: string, app_password: string}
     */
    private function normalize(array $input): array
    {
        return [
            'base_url' => rtrim(trim($input['base_url']), '/'),
            'username' => trim($input['username']),
            // WP shows application passwords space-grouped ("abcd efgh …"); the
            // spaces are cosmetic — strip them so Basic auth matches.
            'app_password' => (string) preg_replace('/\s+/', '', $input['app_password']),
        ];
    }
}
