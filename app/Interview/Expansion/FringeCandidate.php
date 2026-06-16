<?php

namespace App\Interview\Expansion;

/**
 * A genuinely out-of-lane candidate. Phase 2 only TAGS it (fringe) and records the
 * connection + any sibling-brand hint — it builds NO routing page. The collected
 * handoff set feeds the separate Routing layer (the consumer out-of-lane router + B2B
 * partner pages).
 */
final class FringeCandidate
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $connectionNote = null,
        public readonly ?string $siblingBrand = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'connection_note' => $this->connectionNote,
            'sibling_brand' => $this->siblingBrand,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $note = trim((string) ($data['connection_note'] ?? ''));
        $brand = trim((string) ($data['sibling_brand'] ?? ''));

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            connectionNote: $note === '' ? null : $note,
            siblingBrand: $brand === '' ? null : $brand,
        );
    }
}
