<?php

namespace App\Security;

/**
 * Masks secrets for display — `••••` plus the last four characters. Anything
 * surfaced in the UI/API runs through here; the unmasked value is only ever
 * returned by the explicit, audited reveal action.
 */
class CredentialMasker
{
    private const DOTS = '••••';

    public function mask(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return self::DOTS;
        }

        $last4 = mb_substr($value, -4);

        // Don't leak length for very short secrets — show dots only.
        if (mb_strlen($value) <= 4) {
            return self::DOTS;
        }

        return self::DOTS.$last4;
    }

    /**
     * Mask every scalar leaf of a credentials array (keys preserved).
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function maskArray(array $credentials): array
    {
        $masked = [];
        foreach ($credentials as $key => $value) {
            if (is_array($value)) {
                $masked[$key] = $this->maskArray($value);
            } elseif (is_scalar($value)) {
                $masked[$key] = $this->mask((string) $value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
