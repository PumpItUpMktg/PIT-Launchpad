<?php

namespace App\Integrations\Google;

use DateTimeImmutable;

/**
 * A normalized OAuth token set from Google's token endpoint. A refresh response
 * usually omits the refresh token (the original stays valid), so it is nullable.
 */
final class GoogleToken
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $scopes = [],
    ) {}
}
