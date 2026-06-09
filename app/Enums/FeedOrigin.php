<?php

namespace App\Enums;

/**
 * Where a feed (Source) came from. Provenance only — origin is an INGEST-TIME
 * attribute and must never leak past the parse→NewsItem convergence: dedup,
 * recency, scoring, routing and drafting are all origin-blind.
 *
 * - Generated: a materialized projection of the §5 keyword-map × market geo,
 *   kept current by the reconcile job (regenerable; retired by deactivation).
 * - Client: a durable, client-supplied direct RSS/Atom feed URL.
 */
enum FeedOrigin: string
{
    case Generated = 'generated';
    case Client = 'client';
}
