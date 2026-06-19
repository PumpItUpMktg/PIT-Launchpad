<?php

namespace App\Interview\Arrange;

use App\Enums\ArrangeFlagType;

/**
 * One judgment call auto-arrange parked for operator confirm: the spoke it concerns,
 * a plain-language reason, and the runner-up candidates (with their scores) so the
 * surface can show "this vs that". Advisory only — the default is already applied;
 * the flag just asks for a look.
 */
final class ArrangeFlag
{
    /**
     * @param  list<array{id: string, name: string, score: float}>  $candidates
     */
    public function __construct(
        public readonly ArrangeFlagType $type,
        public readonly string $spokeId,
        public readonly string $message,
        public readonly array $candidates = [],
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
        ];
    }
}
