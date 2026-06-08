<?php

namespace App\Enums;

/**
 * The relevance-scoring outcome band: above threshold → draft-ready; borderline
 * → parked/surfaced for operator promotion; below (or gated) → dropped.
 */
enum RelevanceBand: string
{
    case DraftReady = 'draft_ready';
    case Borderline = 'borderline';
    case Dropped = 'dropped';
}
