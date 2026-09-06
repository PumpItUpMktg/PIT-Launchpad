<?php

namespace App\Enums;

/**
 * Lifecycle of a standard-mode DataForSEO task: pending (posted, awaiting
 * tasks_ready), ingested (collected + cached), failed (errored/expired —
 * retained and surfaced, never silently dropped), or no_results (DataForSEO ran
 * the query and Google returned an empty results page — status_code 40102).
 *
 * `no_results` is TERMINAL and distinct from `failed` on purpose: it means the
 * query itself isn't a searchable term (a taxonomy label leaked into the keyword
 * set), not that collection broke. The dispatcher refuses to re-post a query that
 * already came back no_results, so the same dead query is never billed twice.
 */
enum SerpTaskState: string
{
    case Pending = 'pending';
    case Ingested = 'ingested';
    case Failed = 'failed';
    case NoResults = 'no_results';
}
