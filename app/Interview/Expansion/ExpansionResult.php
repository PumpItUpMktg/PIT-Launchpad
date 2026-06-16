<?php

namespace App\Interview\Expansion;

/**
 * The validated candidate tree: the proposed silos (pillar + spokes, including the
 * audience and brand silos) plus the fringe handoff set destined for the Routing
 * layer. Immutable; round-trips to array for the CLI's --json and for persistence.
 */
final class ExpansionResult
{
    /**
     * @param  list<CandidateSilo>  $silos
     * @param  list<FringeCandidate>  $fringeHandoff
     */
    public function __construct(
        public readonly array $silos,
        public readonly array $fringeHandoff = [],
    ) {}

    public function spokeCount(): int
    {
        return array_sum(array_map(fn (CandidateSilo $s) => count($s->spokes), $this->silos));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'silos' => array_map(fn (CandidateSilo $s) => $s->toArray(), $this->silos),
            'fringe_handoff' => array_map(fn (FringeCandidate $f) => $f->toArray(), $this->fringeHandoff),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $silos = is_array($data['silos'] ?? null) ? $data['silos'] : [];
        $fringe = is_array($data['fringe_handoff'] ?? null) ? $data['fringe_handoff'] : [];

        return new self(
            silos: array_values(array_map(
                fn (array $s) => CandidateSilo::fromArray($s),
                array_filter($silos, 'is_array'),
            )),
            fringeHandoff: array_values(array_map(
                fn (array $f) => FringeCandidate::fromArray($f),
                array_filter($fringe, 'is_array'),
            )),
        );
    }
}
