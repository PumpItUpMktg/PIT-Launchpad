<?php

namespace App\JobCapture\Capture;

/**
 * The pushed "First L." display name derived from an internal-only full client name (privacy contract §4).
 * The one place the derivation lives — the operator add-job form, the CSV import, and the review edit all
 * route through it so a client is never published under more than their first name and last initial.
 */
final class ClientDisplayName
{
    public static function from(?string $full): ?string
    {
        $full = trim((string) $full);
        if ($full === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $full) ?: [$full];
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = (string) end($parts);

        return $parts[0].' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }
}
