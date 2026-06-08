<?php

namespace App\Security;

use App\Models\Connection;

/**
 * One row in the staleness report: the connection, its threshold, and how long
 * since it was last rotated. `neverRotated` distinguishes "overdue" from "no
 * rotation on record".
 */
final class StaleConnection
{
    public function __construct(
        public readonly Connection $connection,
        public readonly int $thresholdDays,
        public readonly ?int $daysSinceRotation,
    ) {}

    public function neverRotated(): bool
    {
        return $this->daysSinceRotation === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connection->id,
            'site_id' => $this->connection->site_id,
            'provider' => $this->connection->provider->value,
            'last_rotated_at' => $this->connection->last_rotated_at?->toIso8601String(),
            'days_since_rotation' => $this->daysSinceRotation,
            'threshold_days' => $this->thresholdDays,
            'never_rotated' => $this->neverRotated(),
        ];
    }
}
