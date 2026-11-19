<?php

namespace App\Social\Provider;

use App\Enums\SocialNetwork;

/**
 * One PROFILE in the agency's posting-provider account (Upload-Post: a "user") — the container a client's
 * social logins are connected to. Listed so an operator picks a tenant's profile from what exists instead of
 * typing its name; `connected` names what is signed in on it, per network (a Page name, an Instagram handle,
 * the Google account), so the pick can be checked at a glance.
 */
final class AccountProfile
{
    /** @param  array<string, string>  $connected  network value → what is connected (display name) */
    public function __construct(
        public readonly string $name,
        public readonly array $connected = [],
    ) {}

    public function has(SocialNetwork $network): bool
    {
        return isset($this->connected[$network->value]);
    }

    /** "Facebook: Sump Pump Gurus Corporate · Instagram: sumppumpgurus · Google Business Profile: Eric" — or "nothing connected". */
    public function summary(): string
    {
        if ($this->connected === []) {
            return 'nothing connected';
        }
        $parts = [];
        foreach (SocialNetwork::cases() as $network) {
            if (isset($this->connected[$network->value])) {
                $what = trim($this->connected[$network->value]);
                $parts[] = $network->label().($what !== '' ? ': '.$what : '');
            }
        }

        return implode(' · ', $parts);
    }
}
