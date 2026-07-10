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
     * @param  list<array{anchor: string, path: string}>  $relatedLinks  other pages' permalinks (real internal-link targets)
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
        public readonly array $relatedLinks = [],
        // A human page descriptor (e.g. "About", "Why Choose Us") for standard pages, so the drafter
        // knows the page's intent — the coarse PageType (utility) doesn't carry it. Null otherwise.
        public readonly ?string $pageLabel = null,
        // Captured brand-narrative intake the page composes from (story / mission / values /
        // differentiators), keyed by field — ONLY the fields actually present. The drafter grounds on
        // these and never invents beyond them; absent fields simply aren't here (degrade by omission).
        public readonly array $narrative = [],
        /**
         * Honest OPERATIONAL facts (§1 data): emergency availability, business hours, contact
         * channels. The only operational claims a draft may make — an FAQ answering "do you handle
         * emergencies?" must read the real answer here, never invent one. Absent facts simply aren't
         * present (degrade by omission).
         *
         * @var array<string, mixed>
         */
        public readonly array $facts = [],
        /**
         * A LOCATION page's subject — the pinned §1 Location resolved to prompt-ready facts:
         * city/state, the location's own phone, the served-towns names, the operator's market notes
         * (trusted VERBATIM — their local knowledge), and the trade-keyed grounded local facts
         * (climate normals, elevation, census — cached on the Location). The ONLY local color the
         * drafter may use; empty for every other page type.
         *
         * @var array<string, mixed>
         */
        public readonly array $location = [],
    ) {}
}
