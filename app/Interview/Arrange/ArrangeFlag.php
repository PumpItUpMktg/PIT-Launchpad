<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;

/**
 * One judgment call auto-arrange auto-applied and parked for operator confirm: the spoke it
 * concerns, a plain-language reason, the runner-up candidates (with scores) so the surface can
 * show "this vs that", and the concrete `alternative` a dismiss reverts to. The pick is already
 * applied to the working structure (flagged); accept confirms it, dismiss applies the alternative.
 */
final class ArrangeFlag
{
    /**
     * @param  list<array{id: string, name: string, score: float}>  $candidates
     * @param  array<string, mixed>  $alternative  the dismiss revert target, keyed per flag type
     *                                             (e.g. ['spoke_id' => …], ['silo' => …], ['keyword' => …])
     */
    public function __construct(
        public readonly ArrangeFlagType $type,
        public readonly string $spokeId,
        public readonly string $message,
        public readonly array $candidates = [],
        public readonly array $alternative = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'spoke_id' => $this->spokeId,
            'message' => $this->message,
            'candidates' => $this->candidates,
            'alternative' => $this->alternative,
        ];
    }
}
