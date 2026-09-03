<?php

namespace App\Observers;

use App\Console\Commands\ReconcileSiteCountersCommand;
use App\Console\Commands\ResetPublishCommand;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Operator\SiteHealthCounters;

/**
 * Keeps the persisted `sites` content-status counters in sync on every single-model `Content` write.
 * Recompute-from-source (not delta) so it is safe against overlapping events; it only fires the recompute
 * when a write could actually move a tracked bucket (a create/delete/restore of a tracked-status row, or a
 * status change touching a tracked status) — an ordinary body/SEO edit costs nothing.
 *
 * Bulk `whereIn(...)->update()` writes bypass this (they don't fire model events); those call sites
 * ({@see ResetPublishCommand}) recompute explicitly, and
 * {@see ReconcileSiteCountersCommand} is the scheduled drift net.
 */
class ContentObserver
{
    public function __construct(private readonly SiteHealthCounters $counters) {}

    public function created(Content $content): void
    {
        if ($this->tracked($content->status)) {
            $this->counters->recomputeContent((string) $content->site_id);
        }
    }

    public function updated(Content $content): void
    {
        // A status transition — recompute only when the old OR new status is one we tally.
        if ($content->wasChanged('status') && ($this->tracked($content->status) || $this->tracked($content->getOriginal('status')))) {
            $this->counters->recomputeContent((string) $content->site_id);
        }
    }

    public function deleted(Content $content): void
    {
        // Soft delete: the row leaves the (soft-delete-scoped) tally for its status.
        if ($this->tracked($content->status)) {
            $this->counters->recomputeContent((string) $content->site_id);
        }
    }

    public function restored(Content $content): void
    {
        if ($this->tracked($content->status)) {
            $this->counters->recomputeContent((string) $content->site_id);
        }
    }

    public function forceDeleted(Content $content): void
    {
        if ($this->tracked($content->status)) {
            $this->counters->recomputeContent((string) $content->site_id);
        }
    }

    /** Is this status one the persisted counters tally? */
    private function tracked(ContentStatus|string|null $status): bool
    {
        $value = $status instanceof ContentStatus ? $status->value : (string) $status;

        return array_key_exists($value, SiteHealthCounters::CONTENT_COUNTERS);
    }
}
