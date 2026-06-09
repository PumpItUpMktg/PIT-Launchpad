<?php

namespace App\Enums;

/**
 * The lifecycle of a launch run — one orchestrated full-site push to WordPress.
 *
 * - Running:   the push is in flight.
 * - Completed: the sequence finished. Per-item failures are isolated and recorded
 *              in the run (a completed run may still contain skips/failures); the
 *              run itself completing is independent of individual item outcomes.
 * - Blocked:   refused before any push — no present, non-compromised WordPress
 *              connection (the launch gate doing its job).
 */
enum LaunchRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Blocked = 'blocked';
}
