<?php

namespace App\Interview\Prune;

use App\Enums\KeywordIntent;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Spoke;

/**
 * One spoke as shown in the prune plan: its tag + volume + (for connecting) connection
 * note + the Phase-3 granularity recommendation, plus whether it's still an undecided
 * candidate. The visible candidate list the owner confirms against.
 */
final class PruneRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $silo,
        public readonly string $name,
        public readonly SpokeTag $tag,
        public readonly SpokePageType $pageType,
        public readonly SpokeStatus $status,
        public readonly ?int $volume,
        public readonly SpokeGranularity $granularity,
        public readonly ?string $connectionNote,
        public readonly bool $isPillar,
        public readonly ?KeywordIntent $intent = null,
    ) {}

    public function isFringe(): bool
    {
        return $this->tag === SpokeTag::Fringe;
    }

    public function isDecided(): bool
    {
        return $this->status !== SpokeStatus::Candidate;
    }

    public static function fromSpoke(Spoke $spoke): self
    {
        return new self(
            id: $spoke->id,
            silo: (string) ($spoke->silo ?? ''),
            name: $spoke->name,
            tag: $spoke->tag,
            pageType: $spoke->page_type,
            status: $spoke->status,
            volume: $spoke->volume,
            granularity: $spoke->granularity,
            connectionNote: $spoke->connection_note,
            isPillar: (bool) $spoke->is_pillar,
            intent: $spoke->intent,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'silo' => $this->silo,
            'name' => $this->name,
            'tag' => $this->tag->value,
            'page_type' => $this->pageType->value,
            'status' => $this->status->value,
            'volume' => $this->volume,
            'granularity' => $this->granularity->value,
            'intent' => $this->intent?->value,
            'connection_note' => $this->connectionNote,
            'is_pillar' => $this->isPillar,
        ];
    }
}
