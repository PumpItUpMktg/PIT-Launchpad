<?php

namespace App\Enums;

/**
 * Lifecycle of a standard-mode DataForSEO task: pending (posted, awaiting
 * tasks_ready), ingested (collected + cached), or failed (errored/expired —
 * retained and surfaced, never silently dropped).
 */
enum SerpTaskState: string
{
    case Pending = 'pending';
    case Ingested = 'ingested';
    case Failed = 'failed';
}
