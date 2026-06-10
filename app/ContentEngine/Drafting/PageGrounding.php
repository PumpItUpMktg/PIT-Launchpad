<?php

namespace App\ContentEngine\Drafting;

use App\Enums\PageType;
use App\PageBuilder\Schema\KitSchema;

/**
 * The grounding for a PAGE draft — structurally distinct from the news Grounding:
 * it carries the intake entities a page is built from (services + their problems,
 * offers, substantiated proof, the site's markets, branding/NAP) plus the active
 * voice and the kit slot contract. No news source/claims-as-news/town injection.
 * All fields are prompt-ready (arrays) except proof (Claim value objects).
 */
final class PageGrounding
{
    /**
     * @param  array<string, mixed>  $voiceProfile
     * @param  list<array<string, mixed>>  $services
     * @param  list<array<string, mixed>>  $problems
     * @param  list<array<string, mixed>>  $offers
     * @param  list<Claim>  $proof
     * @param  list<array<string, mixed>>  $markets
     * @param  array<string, mixed>  $branding
     */
    public function __construct(
        public readonly KitSchema $kit,
        public readonly PageType $pageType,
        public readonly array $voiceProfile,
        public readonly int $voiceProfileVersion,
        public readonly array $services,
        public readonly array $problems,
        public readonly array $offers,
        public readonly array $proof,
        public readonly array $markets,
        public readonly array $branding,
        public readonly ?string $targetKeyword = null,
    ) {}
}
