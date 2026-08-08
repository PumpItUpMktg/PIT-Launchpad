<?php

namespace App\Console\Commands;

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Probe a tenant's WordPress from the Artisan box (no shell curl needed): does the `Authorization`
 * header survive the trip, and are Application Passwords available? It hits the companion plugin's
 * public `auth-check` endpoint (sending Basic auth so edge/host stripping is visible) and prints a
 * plain-language diagnosis — the CLI twin of the connect page's 401 explanation.
 */
class DiagnoseWordpress extends Command
{
    protected $signature = 'launchpad:diagnose-wordpress {site : A Site id, or a domain / base URL}
        {--user= : WordPress username (defaults to the saved connection, else launchpad-sync)}
        {--password= : Application password (defaults to the saved connection, else a dummy just to send a header)}';

    protected $description = 'Check whether a tenant WordPress receives the Authorization header (stripped-in-transit vs delivered) and whether Application Passwords are available.';

    public function handle(WordpressClientFactory $factory): int
    {
        $arg = trim((string) $this->argument('site'));
        [$baseUrl, $user, $pass] = $this->resolve($arg);

        if ($baseUrl === null || trim($baseUrl) === '') {
            $this->error("Could not resolve a WordPress URL from '{$arg}' — pass a site id with a saved WP URL, or the domain directly.");

            return self::FAILURE;
        }

        $diag = $factory->usingCredentials(
            ['base_url' => $baseUrl, 'username' => $user, 'app_password' => $pass],
            null,
        )->authCheck();

        if ($diag === null) {
            $this->warn("The auth-check endpoint didn't answer at {$baseUrl}/wp-json/launchpad/v1/auth-check.");
            $this->line('  → Either the companion plugin is older than 0.9.32 (update it), or the host/edge is blocking the request outright (a 403 before WordPress).');

            return self::FAILURE;
        }

        $received = (bool) ($diag['authorization_received'] ?? false);

        $this->table(['Check', 'Value'], [
            ['Authorization header reached WordPress', $received ? 'yes' : 'NO — stripped in transit'],
            ['Auth scheme', (string) ($diag['scheme'] ?? '—')],
            ['Username WordPress saw', (string) ($diag['username'] ?? '—')],
            ['Application Passwords available', $this->yn($diag['application_passwords_available'] ?? null)],
            ['Site served over HTTPS', $this->yn($diag['is_ssl'] ?? null)],
            ['Companion plugin version', (string) ($diag['plugin_version'] ?? '—')],
        ]);

        if (! $received) {
            $this->error('Diagnosis: the Authorization header is being STRIPPED before WordPress (Cloudflare edge, or nginx/FastCGI/Apache).');
            $this->line('  → Fix: launchpad:configure-cloudflare '.$this->hostOf($baseUrl).'  (or forward the header at the origin), then re-run this.');

            return self::FAILURE;
        }

        if (($diag['application_passwords_available'] ?? true) === false) {
            $this->error('Diagnosis: the header arrives, but WordPress has Application Passwords DISABLED (needs HTTPS, or a security plugin re-enabled).');

            return self::FAILURE;
        }

        $this->info('Diagnosis: the Authorization header reaches WordPress and Application Passwords are available — if connect still 401s, it is the username/password (regenerate the Application Password for the connecting user).');

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: string, 2: string} [baseUrl, username, appPassword]
     */
    private function resolve(string $arg): array
    {
        $connection = null;
        if (str_contains($arg, '.')) {
            $baseUrl = $arg;
        } else {
            $connection = Connection::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $arg)
                ->where('provider', ConnectionProvider::WpAppPassword->value)
                ->first();
            $baseUrl = $connection?->credentials['base_url'] ?? Site::query()->whereKey($arg)->value('domain_url');
        }

        // A bare domain needs a scheme for the HTTP call.
        if (is_string($baseUrl) && $baseUrl !== '' && ! str_contains($baseUrl, '://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $user = (string) ($this->option('user') ?: ($connection?->credentials['username'] ?? 'launchpad-sync'));
        $pass = (string) ($this->option('password') ?: ($connection?->credentials['app_password'] ?? 'diagnostic-check'));

        return [$baseUrl, $user, $pass];
    }

    private function yn(mixed $value): string
    {
        return $value === null ? 'unknown' : ($value ? 'yes' : 'no');
    }

    private function hostOf(string $url): string
    {
        $value = str_contains($url, '://') ? $url : 'https://'.$url;

        return (string) (parse_url($value, PHP_URL_HOST) ?: $url);
    }
}
