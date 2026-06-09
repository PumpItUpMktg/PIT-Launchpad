<?php

namespace App\Integrations\Conversions;

use RuntimeException;

/**
 * A normalized failure from a CRM conversion source (Krayin / Mautic): auth,
 * quota, or an error envelope. Surfaced loudly; the ingest job isolates it per
 * provider so one source being down never aborts the others.
 */
class ConversionSourceException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }
}
