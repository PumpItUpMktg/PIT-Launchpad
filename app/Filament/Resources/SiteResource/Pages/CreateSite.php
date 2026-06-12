<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Site;
use App\Operator\Controls\WordpressConnector;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    /**
     * Create the Site from its real columns only, then (best-effort) verify and
     * wire the WordPress connection from the wizard's step-2 fields — which are NOT
     * Site columns. A failed verification never loses the tenant: the Site is kept
     * and the operator is told to wire the connection later. Verify-before-store
     * means a successful connect lands clean (compromised=false) and passes §9's
     * launch gate immediately.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $site = Site::create([
            'account_id' => $data['account_id'],
            'brand_name' => $data['brand_name'],
            'legal_name' => $data['legal_name'] ?? null,
            'domain_url' => $data['domain_url'] ?? null,
            'status' => $data['status'] ?? SiteStatus::Onboarding->value,
        ]);

        $this->connectWordpress($site, $data);

        return $site;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function connectWordpress(Site $site, array $data): void
    {
        $password = trim((string) ($data['app_password'] ?? ''));
        $baseUrl = trim((string) ($data['base_url'] ?? '')) ?: trim((string) ($data['domain_url'] ?? ''));

        if ($password === '' || $baseUrl === '') {
            return;
        }

        $username = trim((string) ($data['username'] ?? '')) ?: 'launchpad-sync';

        try {
            app(WordpressConnector::class)->connect($site->id, [
                'base_url' => $baseUrl,
                'username' => $username,
                'app_password' => $password,
            ]);

            Notification::make()->success()
                ->title('WordPress connected')
                ->body('Verified and saved — this site can publish.')
                ->send();
        } catch (WordpressException $e) {
            Notification::make()->warning()
                ->title('Site created — WordPress not connected')
                ->body($e->getMessage().' Wire it later under Controls → Connections.')
                ->send();
        }
    }
}
