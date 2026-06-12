<?php

namespace App\Integrations\Places;

/**
 * The result of the Places API smoke-test: is the API reachable + enabled on
 * the project, and if not, an operator-facing reason (REQUEST_DENIED usually
 * means the Places API isn't enabled on the GCP project / the key is restricted).
 */
final class PlacesStatus
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
    ) {}

    public static function ok(): self
    {
        return new self(true, 'Places API reachable.');
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
