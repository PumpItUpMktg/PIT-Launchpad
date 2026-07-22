<?php

namespace App\Publishing\Links;

use App\Enums\LinkFindingType;

/**
 * One internal-link audit finding — a published page that needs an inbound link, an outbound link, or
 * a link it should carry to a page it already names. `suggestion` names the concrete other page (id +
 * label) the fix would link, so a corrective pass has a deterministic target.
 */
final class LinkFinding
{
    public function __construct(
        public readonly LinkFindingType $type,
        public readonly string $contentId,
        public readonly string $url,
        public readonly string $title,
        public readonly string $detail,
        public readonly ?string $suggestedContentId = null,
        public readonly ?string $suggestedLabel = null,
    ) {}
}
