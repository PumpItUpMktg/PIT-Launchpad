<?php

namespace App\Console\Commands;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Controls\CloudflareConfigurator;
use Illuminate\Console\Command;

/**
 * Auto-configure a tenant's Cloudflare edge for the control-plane sync — the CLI twin of the connect
 * page's "Auto-configure Cloudflare" action. Accepts a site id (resolves its WordPress URL) or a bare
 * domain/URL.
 */
class ConfigureCloudflare extends Command
{
    protected $signature = 'launchpad:configure-cloudflare {site : A Site id, or a domain / base URL}';

    protected $description = 'Create/refresh the Cloudflare WAF skip rule for /wp-json/launchpad/* so the WordPress sync is not blocked or stripped at the edge.';

    public function handle(CloudflareConfigurator $configurator): int
    {
        $arg = trim((string) $this->argument('site'));

        $baseUrl = str_contains($arg, '.') ? $arg : $this->baseUrlForSite($arg);
        if ($baseUrl === null || trim($baseUrl) === '') {
            $this->error("Could not resolve a WordPress URL from '{$arg}' — pass a site id with a saved WP URL, or the domain directly.");

            return self::FAILURE;
        }

        $result = $configurator->configureForUrl($baseUrl);
        $result->ok ? $this->info($result->message) : $this->error($result->message);

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    /** The site's WordPress base URL — the saved app-password Connection first, else the site domain. */
    private function baseUrlForSite(string $siteId): ?string
    {
        $connection = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('provider', ConnectionProvider::WpAppPassword->value)
            ->first();

        $base = $connection?->credentials['base_url'] ?? null;
        if (is_string($base) && $base !== '') {
            return $base;
        }

        return Site::query()->whereKey($siteId)->value('domain_url');
    }
}
