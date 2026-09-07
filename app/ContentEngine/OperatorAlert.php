<?php

namespace App\ContentEngine;

use App\ContentEngine\Feeds\FeedIngestReport;
use App\Enums\AlertType;

/**
 * An operator alert raised by the funnel — an EPHEMERAL value object carried in {@see FunnelResult} and
 * summarized into the ingest run's log/report ({@see FeedIngestReport}); it is NOT
 * persisted and no standing UI reads it. (An earlier docblock claimed "§6c's flagged lane consumes these" —
 * it never did: the §6c flagged lane derives from DURABLE Content state, e.g. `near_dup_of_content_id`, not
 * from these objects. A comment asserting a wiring that doesn't exist is worse than none.) So a decision that
 * must be VISIBLE to an operator — a held near-duplicate — is carried by that durable column, not by this
 * alert; this remains only the ingest-run breadcrumb.
 */
final class OperatorAlert
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly AlertType $type,
        public readonly ?string $contentId,
        public readonly string $message,
        public readonly array $context = [],
    ) {}
}
