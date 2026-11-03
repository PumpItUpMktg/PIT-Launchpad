<?php

namespace App\Integrations\ColdOutreach;

use App\Models\Site;

/** Deliverability lookup seam (Bird). One verdict per input, in input order. */
interface EmailVerifier
{
    /**
     * @param  list<string>  $emails
     * @return list<EmailVerdict>
     */
    public function verify(Site $site, array $emails): array;
}
