<?php

namespace App\Enums;

/**
 * What put a draft into the §6 pipeline. Directed work enters via Gap (a §5
 * gap-brief); reactive work via News (a §6a candidate). Seasonal, OnDemand, and
 * Backfill are operator/calendar-initiated lanes that reuse the same drafter.
 */
enum DraftTrigger: string
{
    case Gap = 'gap';
    case News = 'news';
    case OnDemand = 'on_demand';
    case Seasonal = 'seasonal';
    case Backfill = 'backfill';

    /**
     * Reactive triggers carry timeliness (and may carry local relevance);
     * directed/evergreen triggers do not.
     */
    public function isReactive(): bool
    {
        return $this === self::News;
    }
}
