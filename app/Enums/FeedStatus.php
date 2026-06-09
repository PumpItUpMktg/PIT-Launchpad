<?php

namespace App\Enums;

/**
 * The surfaced health of a feed (Source), derived — never stored — from the
 * enabled toggle plus the fetch telemetry (last_item_at / last_error). Drives
 * the per-feed health badge in the News Sources panel.
 *
 * - Active:    enabled and producing items within the freshness window.
 * - Paused:    disabled by the client (the enabled toggle is off).
 * - Unhealthy: enabled but silent for too long, or failing to fetch.
 */
enum FeedStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Unhealthy = 'unhealthy';
}
