<?php

namespace App\Interview\Expansion;

use App\Enums\SpokePageType;

/**
 * A proposed silo (pillar + spokes). The silo name is the pillar topic; the audience
 * axis (e.g. "Commercial & Industrial") and the brand axis (e.g. "Brands We Service")
 * manifest as their own silos here rather than as new fields — they fit the
 * silo→spoke model as-is.
 */
final class CandidateSilo
{
    /**
     * @param  list<CandidateSpoke>  $spokes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $spokes = [],
        public readonly string $headKeyword = '',
        public readonly SpokePageType $pageType = SpokePageType::Service,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'head_keyword' => $this->headKeyword,
            'page_type' => $this->pageType->value,
            'spokes' => array_map(fn (CandidateSpoke $s) => $s->toArray(), $this->spokes),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $spokes = is_array($data['spokes'] ?? null) ? $data['spokes'] : [];

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            spokes: array_values(array_map(
                fn (array $s) => CandidateSpoke::fromArray($s),
                array_filter($spokes, 'is_array'),
            )),
            headKeyword: trim((string) ($data['head_keyword'] ?? '')),
            pageType: SpokePageType::tryFrom(EnumNormalizer::normalize($data['page_type'] ?? '')) ?? SpokePageType::Service,
        );
    }
}
