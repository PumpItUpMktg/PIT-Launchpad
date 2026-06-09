<?php

namespace App\Enums;

/**
 * DataForSEO request mode. Standard is task-based (task_post → ingest when
 * ready) — cheaper, the everyday path. Live hits the synchronous /live/
 * endpoints for interactive/on-demand fetches where a user needs an answer now.
 */
enum DataForSeoMode: string
{
    case Standard = 'standard';
    case Live = 'live';
}
