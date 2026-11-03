<?php

namespace App\Integrations\ColdOutreach;

use App\Models\ColdContact;
use App\Models\Site;

/** Marketing-list seam (Brevo): where warm contacts land. */
interface MarketingPlatform
{
    /** Create or update the contact and add it to the list. Returns the platform contact id when known. */
    public function upsertContact(Site $site, int $listId, ColdContact $contact): ?string;
}
