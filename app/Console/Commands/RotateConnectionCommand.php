<?php

namespace App\Console\Commands;

use App\Enums\CredentialType;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Security\ConnectionRotator;
use Illuminate\Console\Command;

/**
 * Per-tenant, no-downtime credential rotation: rotate → verify → revoke. The new
 * credential is verified with a live provider call before the old one is
 * replaced (handled by ConnectionRotator). The secret is never echoed.
 */
class RotateConnectionCommand extends Command
{
    protected $signature = 'launchpad:rotate-connection
        {site : The Site id}
        {type : Credential type (wp_app_password|gbp_token|ga4_token|ghl_token)}
        {--credentials= : New credentials as a JSON object (omit to be prompted)}';

    protected $description = 'Rotate a per-tenant Connection credential with verify-before-revoke.';

    public function handle(ConnectionRotator $rotator): int
    {
        $site = Site::find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $type = CredentialType::tryFrom((string) $this->argument('type'));
        if ($type === null) {
            $this->error('Unknown credential type. Expected one of: '
                .implode(', ', array_map(fn (CredentialType $t) => $t->value, CredentialType::cases())));

            return self::FAILURE;
        }

        $connection = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', $type->provider()->value)
            ->first();

        if ($connection === null) {
            $this->error("No {$type->value} connection found for that site.");

            return self::FAILURE;
        }

        $credentials = $this->readCredentials();
        if ($credentials === null) {
            $this->error('Credentials must be a JSON object.');

            return self::FAILURE;
        }

        $result = $rotator->rotate($connection, $credentials);

        if (! $result->ok) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info($result->message);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCredentials(): ?array
    {
        $raw = $this->option('credentials');
        if ($raw === null) {
            $raw = $this->secret('Paste the new credentials as JSON') ?? '';
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
