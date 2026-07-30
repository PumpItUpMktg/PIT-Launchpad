<?php

namespace App\Operator\Controls;

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressClient;
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
            throw new WordpressException($this->explainFailure($credentials['base_url'], $result, $client));
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
     * authenticate". Distinguishes an unreachable host, a wrong URL / inactive plugin (404), an
     * app-password rejection vs a missing capability (401/403 — split by whether the SAME credential
     * authenticates against core WordPress), and anything else (carrying WP's own reason).
     *
     * @param  array{ok: bool, status: int, error: ?string}  $result
     */
    private function explainFailure(string $baseUrl, array $result, WordpressClient $client): string
    {
        $endpoint = $baseUrl.'/wp-json/launchpad/v1/status';
        $tail = ' — nothing was saved.';

        return match (true) {
            $result['status'] === 0 => "Could not reach {$baseUrl} — check the URL and that the site is live (DNS / timeout)".$tail,
            $result['status'] === 404 => "The Launchpad companion plugin isn't answering at {$endpoint} (HTTP 404) — confirm the plugin is active on THIS host and the URL matches WordPress's Site Address (Settings → General)".$tail,
            in_array($result['status'], [401, 403], true) && $client->coreAuthWorks() => "The credential authenticates, but this WordPress user lacks the Launchpad capability (HTTP {$result['status']} on the plugin route) — give the connecting user the Launchpad role / lp_manage_content capability".$tail,
            in_array($result['status'], [401, 403], true) => "WordPress rejected the app password (HTTP {$result['status']}) — regenerate the Application Password, confirm the username is the user it was created for, and if it still fails your host may be stripping the Authorization header (common on nginx / FastCGI / some managed hosts), which must be forwarded for Application Passwords to work".$tail,
            default => "WordPress returned HTTP {$result['status']} at {$endpoint}".($result['error'] !== null ? ' — '.$result['error'] : '').$tail,
        };
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
