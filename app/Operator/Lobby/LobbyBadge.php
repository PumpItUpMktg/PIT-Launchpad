<?php

namespace App\Operator\Lobby;

use App\Enums\LobbyBadgeTier;

/**
 * One attention badge on a lobby card. Immutable. `key` is a stable identifier the Filament layer maps
 * to the filtered surface it links to (a badge is navigation only — it never mutates). `count` is null
 * for state badges that read as an icon, not a number (e.g. a broken WordPress connection); `detail`
 * carries a duration-style label where a raw count would mislead (e.g. "no items for 6 days").
 */
final class LobbyBadge
{
    public function __construct(
        public readonly string $key,
        public readonly LobbyBadgeTier $tier,
        public readonly string $label,
        public readonly ?int $count = null,
        public readonly ?string $detail = null,
    ) {}

    public function color(): string
    {
        return $this->tier->color();
    }
}
