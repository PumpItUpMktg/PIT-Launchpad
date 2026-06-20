<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Enums\SiteStatus;
use App\Filament\Pages\Guided\Business;
use App\Filament\Resources\SiteResource;
use App\Guided\StepGate;
use App\Models\Site;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * "Add new site" is the on-ramp to the unified onboarding flow. Creation captures only the
 * business basics (name + account); it initializes the site's setup_state and drops the operator
 * into Step 1 (Business). WordPress is connected + prepped at Step 2 and the brand pushed at Step
 * 3 — no more separate site record + separate brand state, and the brand push can't run before
 * WordPress is ready.
 */
class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return Site::create([
            'account_id' => $data['account_id'],
            'brand_name' => $data['brand_name'],
            'legal_name' => $data['legal_name'] ?? null,
            'domain_url' => $data['domain_url'] ?? null,
            'status' => $data['status'] ?? SiteStatus::Onboarding->value,
        ]);
    }

    protected function afterCreate(): void
    {
        /** @var Site $site */
        $site = $this->record;
        app(StepGate::class)->state($site);          // initialize one continuous setup_state
        session(['guided_site_id' => $site->id]);    // the guided flow resolves the working site
    }

    protected function getRedirectUrl(): string
    {
        return Business::getUrl(); // enter the guided flow at Step 1
    }
}
