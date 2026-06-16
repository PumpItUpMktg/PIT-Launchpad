<?php

namespace App\Interview\Expansion;

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeTag;

/**
 * One proposed spoke in the candidate tree: a service or content page the expansion
 * reasoned out (equipment×action, adjacency, or upstream content). `volume` is left
 * for Phase 3; granularity is the maximal split (own_page) here — Phase 3 folds the
 * low-volume ones. A `connecting` spoke always carries a connection_note.
 */
final class CandidateSpoke
{
    public function __construct(
        public readonly string $name,
        public readonly SpokePageType $pageType,
        public readonly SpokeTag $tag,
        public readonly string $headKeyword = '',
        public readonly ?string $connectionNote = null,
        public readonly SpokeGranularity $granularity = SpokeGranularity::OwnPage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'page_type' => $this->pageType->value,
            'tag' => $this->tag->value,
            'head_keyword' => $this->headKeyword,
            'connection_note' => $this->connectionNote,
            'granularity' => $this->granularity->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $note = trim((string) ($data['connection_note'] ?? ''));

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            pageType: SpokePageType::tryFrom(EnumNormalizer::normalize($data['page_type'] ?? '')) ?? SpokePageType::Service,
            tag: SpokeTag::tryFrom(EnumNormalizer::normalize($data['tag'] ?? '')) ?? SpokeTag::Adjacent,
            headKeyword: trim((string) ($data['head_keyword'] ?? '')),
            connectionNote: $note === '' ? null : $note,
            granularity: SpokeGranularity::tryFrom(EnumNormalizer::normalize($data['granularity'] ?? '')) ?? SpokeGranularity::OwnPage,
        );
    }
}
