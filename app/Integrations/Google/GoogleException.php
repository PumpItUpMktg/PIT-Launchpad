<?php

namespace App\Integrations\Google;

use RuntimeException;

/**
 * A normalized Google API/OAuth failure. `needsReconnect` marks the case where a
 * refresh token is no longer valid (client revoked access or changed scopes) and
 * the connection must be re-authorized — surfaced, never swallowed.
 */
class GoogleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly bool $fatal = false,
        public readonly bool $needsReconnect = false,
    ) {
        parent::__construct($message);
    }
}
