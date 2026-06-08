<?php

namespace App\Security;

use App\Models\Connection;

/**
 * The outcome of a per-tenant credential rotation. On failure the existing
 * credential is guaranteed untouched (verify-before-revoke).
 */
final class RotationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?Connection $connection = null,
    ) {}

    public static function success(Connection $connection): self
    {
        return new self(true, 'Credential rotated and verified.', $connection);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
