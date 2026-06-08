<?php

namespace App\ContentEngine\Review;

use App\Enums\ContentStatus;
use App\Enums\ReviewFlag;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The review work-queue query: the actionable set of drafts (needs_review, plus
 * borderline in_review and items a publish bounced back as render_failed /
 * publish_failed), ordered flagged-first then oldest. Operator-wide
 * (cross-tenant), filterable by tenant / silo / kind / trigger / alert flag.
 */
class ReviewQueue
{
    /** The statuses that belong in the operator's review queue. */
    public const STATUSES = [
        ContentStatus::NeedsReview,
        ContentStatus::InReview,
        ContentStatus::RenderFailed,
        ContentStatus::PublishFailed,
    ];

    /**
     * @return Builder<Content>
     */
    public static function query(): Builder
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->whereIn('status', self::statusValues());
    }

    /**
     * @return list<string>
     */
    public static function statusValues(): array
    {
        return array_map(fn (ContentStatus $s) => $s->value, self::STATUSES);
    }

    /**
     * Flagged items first (failed/borderline before plain drafts), then oldest.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public static function flaggedFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw(self::priorityOrder())
            ->orderBy('created_at');
    }

    /**
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public static function withFlag(Builder $query, ReviewFlag $flag): Builder
    {
        return AlertFlags::filter($query, $flag);
    }

    /**
     * A status-priority ordering expression: failed and borderline first, plain
     * needs_review last. Portable across SQLite and PostgreSQL.
     */
    public static function priorityOrder(): string
    {
        return 'CASE status'
            ." WHEN '".ContentStatus::RenderFailed->value."' THEN 0"
            ." WHEN '".ContentStatus::PublishFailed->value."' THEN 1"
            ." WHEN '".ContentStatus::InReview->value."' THEN 2"
            .' ELSE 3 END';
    }
}
