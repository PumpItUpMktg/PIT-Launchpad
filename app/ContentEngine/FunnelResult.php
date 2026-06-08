<?php

namespace App\ContentEngine;

use App\Models\Content;

/**
 * The candidate-funnel outcome: draft-ready candidates, borderline-parked
 * candidates, refresh marks, operator alerts, and dropped items with reasons.
 */
final class FunnelResult
{
    /**
     * @param  list<Content>  $created
     * @param  list<Content>  $parked
     * @param  list<RefreshMark>  $refreshMarked
     * @param  list<OperatorAlert>  $alerts
     * @param  list<array{title: string, reason: string}>  $dropped
     */
    public function __construct(
        public readonly array $created,
        public readonly array $parked,
        public readonly array $refreshMarked,
        public readonly array $alerts,
        public readonly array $dropped,
    ) {}
}
