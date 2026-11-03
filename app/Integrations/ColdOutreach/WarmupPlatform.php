<?php

namespace App\Integrations\ColdOutreach;

use App\Models\ColdContact;
use App\Models\Site;

/** Cold-email warm-up seam (Instantly). */
interface WarmupPlatform
{
    /** Add the contact to the campaign's sequence; returns the platform's lead. */
    public function addLead(Site $site, string $campaignId, ColdContact $contact): WarmupLead;

    /**
     * One page of the campaign's leads with their engagement counters.
     *
     * @return array{leads: list<WarmupLead>, next: ?string}
     */
    public function listLeads(Site $site, string $campaignId, ?string $cursor = null): array;
}
