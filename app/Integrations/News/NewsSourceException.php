<?php

namespace App\Integrations\News;

use RuntimeException;

/**
 * A normalized news-source failure: a non-JSON GDELT body (GDELT returns errors
 * as text/HTML even on HTTP 200), a NewsAPI `status:"error"` envelope, or an
 * auth/quota failure. Surfaced — never swallowed, and never a raw parser crash.
 */
class NewsSourceException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly bool $fatal = false)
    {
        parent::__construct($message);
    }
}
