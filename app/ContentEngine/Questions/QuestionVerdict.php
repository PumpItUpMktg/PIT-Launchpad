<?php

namespace App\ContentEngine\Questions;

use App\Models\Content;

/**
 * The coverage verdict for one question topic — what the site should do about it, read off where it
 * already ranks ({@see QuestionCoverage}):
 *
 *  - covered: a post of ours already ranks in the top slots for it. Nothing to do.
 *  - refresh: a post of ours exists (it ranks below the covered bound, or ranks nowhere but reads as the
 *    same topic) — refresh THAT post rather than file a second one. `content` names it.
 *  - author: nothing of ours answers it as a post (the ranking URL is the home page, a hub or a service
 *    page, a legacy URL, or nothing ranks at all) — a new post candidate.
 *  - orphaned: Google still shows a URL whose Launchpad row is retired (soft-deleted or no longer
 *    published) with no redirect to a live post. Nothing live answers it, but filing a new post would
 *    recreate the "-N" duplicate family — the operator decides. `content` names the retired row.
 */
final class QuestionVerdict
{
    public const COVERED = 'covered';

    public const REFRESH = 'refresh';

    public const AUTHOR = 'author';

    public const ORPHANED = 'orphaned';

    public function __construct(
        public readonly string $kind,
        public readonly ?Content $content,
        public readonly ?string $rankingUrl,
        public readonly ?float $position,
        public readonly string $reason,
        public readonly ?float $similarity = null,
    ) {}

    public static function covered(Content $content, string $url, ?float $position): self
    {
        return new self(self::COVERED, $content, $url, $position, 'a post of ours ranks for it');
    }

    public static function refresh(Content $content, ?string $url, ?float $position, string $reason, ?float $similarity = null): self
    {
        return new self(self::REFRESH, $content, $url, $position, $reason, $similarity);
    }

    public static function author(?string $url, ?float $position, string $reason): self
    {
        return new self(self::AUTHOR, null, $url, $position, $reason);
    }

    public static function orphaned(Content $content, string $url, ?float $position, string $reason): self
    {
        return new self(self::ORPHANED, $content, $url, $position, $reason);
    }

    public function isOrphaned(): bool
    {
        return $this->kind === self::ORPHANED;
    }

    public function isCovered(): bool
    {
        return $this->kind === self::COVERED;
    }

    public function isRefresh(): bool
    {
        return $this->kind === self::REFRESH;
    }

    public function isAuthor(): bool
    {
        return $this->kind === self::AUTHOR;
    }
}
