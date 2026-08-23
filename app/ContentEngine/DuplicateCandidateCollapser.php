<?php

namespace App\ContentEngine;

use App\ContentEngine\Review\ReviewActions;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * One-off cleanup for the duplicate candidates the funnel let through before
 * article-identity dedup shipped (the same story re-ingested hourly). Groups the
 * site's still-triageable candidates by article identity — externalId when
 * present, else a normalized title — keeps the earliest, and rejects the rest
 * (status Rejected, reason "duplicate"). Idempotent: a second run finds nothing
 * because a single survivor per group can't form a duplicate group.
 */
class DuplicateCandidateCollapser
{
    public function __construct(private readonly ReviewActions $reviewActions) {}

    /**
     * @return array{groups:int, duplicates:int}
     */
    public function collapse(Site $site): array
    {
        $candidates = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Post->value)
            ->whereIn('status', [ContentStatus::Candidate->value, ContentStatus::Scored->value])
            ->orderBy('created_at')   // earliest first — the keeper of each group
            ->get();

        $groups = $candidates->groupBy(fn (Content $c): string => $this->identity($c))
            ->filter(fn ($group): bool => $group->count() > 1);

        $duplicates = 0;
        foreach ($groups as $group) {
            foreach ($group->skip(1) as $dup) {   // keep the first (earliest); reject the rest
                $this->reviewActions->reject($dup, 'duplicate');
                $duplicates++;
            }
        }

        return ['groups' => $groups->count(), 'duplicates' => $duplicates];
    }

    /** Article identity for grouping: the stable externalId, else a normalized title. */
    private function identity(Content $c): string
    {
        $ext = trim((string) $c->external_id);
        if ($ext !== '') {
            return 'ext:'.$ext;
        }

        return 'title:'.Str::of((string) $c->title)->lower()->squish()->value();
    }
}
