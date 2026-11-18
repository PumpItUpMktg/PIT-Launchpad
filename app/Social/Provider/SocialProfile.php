<?php

namespace App\Social\Provider;

use App\Enums\SocialNetwork;

/** A profile connected in the posting provider: a GBP location, a Facebook Page, an Instagram account. */
final class SocialProfile
{
    public function __construct(
        public readonly string $id,
        public readonly SocialNetwork $network,
        public readonly string $name,
        public readonly ?string $address = null,
        public readonly ?string $placeId = null,
        /** The provider profile (account) this channel is exposed through — a tenant can span several. */
        public readonly ?string $account = null,
    ) {}
}
