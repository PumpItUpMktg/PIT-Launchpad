<?php

namespace App\Console\Commands;

use App\Enums\ConnectionProvider;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Probe a tenant's WordPress from the Artisan box (no shell curl needed) and pin down a stuck connect
 * to its exact cause. Two checks:
 *   1. Public `auth-check` — did the `Authorization` header survive the trip (edge/host stripping)?
 *   2. Authed `status` — does THIS Application Password actually authenticate (200 vs 401 vs 403)?
 * The second only runs when a real password is supplied (option or saved connection); otherwise a dummy
 * credential is used, which still tests header arrival but can't validate the password.
 */
class DiagnoseWordpress extends Command
{
    protected $signature = 'launchpad:diagnose-wordpress {site : A Site id, or a domain / base URL}
        {--user= : WordPress username (defaults to the saved connection, else launchpad-sync)}
        {--password= : Application password (defaults to the saved connection; omit to only test header arrival)}';

    protected $description = 'Diagnose a tenant WordPress connect: does the Authorization header arrive, and does the Application Password authenticate?';

    public function handle(WordpressClientFactory $factory): int
    {
        $arg = trim((string) $this->argument('site'));
        [$baseUrl, $user, $pass, $credsProvided] = $this->resolve($arg);

        if ($baseUrl === null || trim($baseUrl) === '') {
            $this->error("Could not resolve a WordPress URL from '{$arg}' — pass a site id with a saved WP URL, or the domain directly.");

            return self::FAILURE;
        }

        $client = $factory->usingCredentials(
            ['base_url' => $baseUrl, 'username' => $user, 'app_password' => $pass],
            null,
        );

        $diag = $client->authCheck();
        if ($diag === null) {
            $this->warn("The auth-check endpoint didn't answer at {$baseUrl}/wp-json/launchpad/v1/auth-check.");
            $this->line('  → Either the companion plugin is older than 0.9.32 (update it), or the host/edge is blocking the request outright (a 403 before WordPress).');

            return self::FAILURE;
        }

        $received = (bool) ($diag['authorization_received'] ?? false);

        $rows = [
            ['Authorization header reached WordPress', $received ? 'yes' : 'NO — stripped in transit'],
            ['Auth scheme', (string) ($diag['scheme'] ?? '—')],
            ['Username WordPress saw', (string) ($diag['username'] ?? '—')],
            ['Application Passwords available', $this->yn($diag['application_passwords_available'] ?? null)],
            ['Site served over HTTPS', $this->yn($diag['is_ssl'] ?? null)],
            ['Companion plugin version', (string) ($diag['plugin_version'] ?? '—')],
        ];

        // The decisive check: does this exact credential authenticate against the push-capable endpoint?
        $ping = $credsProvided ? $client->pingResult() : null;
        $rows[] = ['Application Password authenticates', $ping === null
            ? 'not tested — pass --password to check'
            : ($ping['ok'] ? 'yes' : 'NO — HTTP '.$ping['status'])];

        $this->table(['Check', 'Value'], $rows);

        return $this->diagnose($received, $diag, $ping, $baseUrl);
    }

    /**
     * @param  array<string, mixed>  $diag
     * @param  array{ok: bool, status: int, error: ?string}|null  $ping
     */
    private function diagnose(bool $received, array $diag, ?array $ping, string $baseUrl): int
    {
        if (! $received) {
            $this->error('Diagnosis: the Authorization header is being STRIPPED before WordPress (Cloudflare edge, or nginx/FastCGI/Apache).');
            $this->line('  → Fix: launchpad:configure-cloudflare '.$this->hostOf($baseUrl).'  (or forward the header at the origin), then re-run this.');

            return self::FAILURE;
        }

        if (($diag['application_passwords_available'] ?? true) === false) {
            $this->error('Diagnosis: the header arrives, but WordPress has Application Passwords DISABLED (needs HTTPS, or a security plugin re-enabled).');

            return self::FAILURE;
        }

        if ($ping === null) {
            $this->info('The header reaches WordPress and Application Passwords are enabled. Re-run with --user and --password to test whether the actual credential authenticates.');

            return self::SUCCESS;
        }

        if ($ping['ok']) {
            $this->info('Diagnosis: ALL GREEN — the header arrives AND this Application Password authenticates. Connect will succeed with these exact credentials.');

            return self::SUCCESS;
        }

        match ($ping['status']) {
            401 => $this->error('Diagnosis: the header arrives, but WordPress REJECTED this Application Password (HTTP 401). Regenerate it on the connecting user (Users → the user → Application Passwords), use username + that new Application Password (NOT the login password), and confirm the app password was created for this exact user.'),
            403 => $this->error('Diagnosis: the credential authenticates, but the user lacks the lp_manage_content capability (HTTP 403). Re-activate the companion plugin (it re-grants the cap), or connect as the launchpad-sync user, then retry.'),
            404 => $this->error('Diagnosis: launchpad/v1/status is not there (HTTP 404) — confirm the companion plugin is active on THIS host and the URL matches WordPress\'s Site Address.'),
            default => $this->error('Diagnosis: WordPress returned HTTP '.$ping['status'].' on the authed endpoint'.($ping['error'] !== null ? ' — '.$ping['error'] : '').'.'),
        };

        return self::FAILURE;
    }

    /**
     * @return array{0: ?string, 1: string, 2: string, 3: bool} [baseUrl, username, appPassword, realCredsProvided]
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

        if (is_string($baseUrl) && $baseUrl !== '' && ! str_contains($baseUrl, '://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $optionPassword = (string) ($this->option('password') ?? '');
        $savedPassword = (string) ($connection?->credentials['app_password'] ?? '');
        $credsProvided = $optionPassword !== '' || $savedPassword !== '';

        $user = (string) ($this->option('user') ?: ($connection?->credentials['username'] ?? 'launchpad-sync'));
        $pass = $optionPassword !== '' ? $optionPassword : ($savedPassword !== '' ? $savedPassword : 'diagnostic-check');

        return [$baseUrl, $user, $pass, $credsProvided];
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
