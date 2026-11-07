<?php

namespace App\Social\Provider;

final class SocialPublishResult
{
    public function __construct(
        public readonly string $providerPostId,
        public readonly ?string $permalink = null,
    ) {}
}
