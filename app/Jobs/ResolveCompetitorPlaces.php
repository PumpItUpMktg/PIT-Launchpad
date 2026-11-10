<?php

namespace App\Jobs;

use App\TownRank\CompetitorPlaceResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Resolve a batch of map-pack competitor place ids to coordinates (one Places call each, once ever). */
class ResolveCompetitorPlaces implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** @param  list<string>  $placeIds */
    public function __construct(public readonly array $placeIds) {}

    public function uniqueId(): string
    {
        $ids = $this->placeIds;
        sort($ids);

        return sha1(implode('|', $ids));
    }

    public function handle(CompetitorPlaceResolver $resolver): void
    {
        $resolver->resolve($this->placeIds);
    }
}
