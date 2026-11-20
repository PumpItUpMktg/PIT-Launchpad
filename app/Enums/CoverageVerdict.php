<?php

namespace App\Enums;

use App\ContentEngine\Coverage\CandidateCoverage;

/**
 * What a blog candidate should become, given what the site already publishes on the topic
 * ({@see CandidateCoverage}):
 *
 * - Author: no live post reads as this topic — a new post is the right move.
 * - Refresh: a live post already covers it — fold the candidate's hook into THAT post (one URL keeps earning)
 *   instead of adding a second post that splits impressions with it.
 * - Covered: the live post already ranks near the top for it and the candidate carries no fresh hook —
 *   nothing to add; a second post would only cannibalise.
 */
enum CoverageVerdict: string
{
    case Author = 'author';
    case Refresh = 'refresh';
    case Covered = 'covered';

    public function label(): string
    {
        return match ($this) {
            self::Author => 'New post',
            self::Refresh => 'Refresh existing',
            self::Covered => 'Covered',
        };
    }
}
