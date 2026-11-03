<?php

namespace App\Integrations\ColdOutreach;

use RuntimeException;

/**
 * A Bird / Instantly / Brevo call failed or the site is missing a key. `fatal` marks errors a retry
 * will not fix (bad key, unpaid plan, missing configuration) so the pipeline stops instead of looping.
 */
class ColdOutreachException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly bool $fatal = false)
    {
        parent::__construct($message, $status);
    }
}
