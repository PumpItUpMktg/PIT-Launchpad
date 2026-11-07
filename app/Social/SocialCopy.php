<?php

namespace App\Social;

/** The composed share for one network: copy as it will be posted, the tags, the image, the raw link, the CTA. */
final class SocialCopy
{
    /** @param  list<string>  $hashtags */
    public function __construct(
        public readonly string $copy,
        public readonly array $hashtags,
        public readonly ?string $imageUrl,
        public readonly ?string $link,
        public readonly ?string $cta,
    ) {}
}
