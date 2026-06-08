<?php

namespace App\SiloCreator;

/**
 * A topical theme must clear a minimum number of problem/keyword matches to be
 * proposed as a silo — so we never create a silo that would only ever hold one
 * post (the same spirit as the thin-page guard).
 */
class ViabilityGuard
{
    public function __construct(private readonly int $threshold = 3) {}

    public function isViable(int $supportCount): bool
    {
        return $supportCount >= $this->threshold;
    }

    public function threshold(): int
    {
        return $this->threshold;
    }
}
