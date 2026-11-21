<?php

namespace App\ContentEngine\Questions;

use App\Models\Content;
use Carbon\Carbon;

/**
 * The two refresh marks a post can carry (`meta.question_refresh`, `meta.news_refresh`) read as one: the
 * latest refresh, when it was refreshed, and when the refreshed content was republished — the moment Google
 * could first see the change, which the crawl check and the progress windows key off.
 */
final class RefreshMarks
{
    public const KEYS = ['question_refresh', 'news_refresh'];

    /** The latest mark by `refreshed_at`, with its key, or null for a post never refreshed. @return array{key: string, mark: array<string, mixed>}|null */
    public static function latest(Content $post): ?array
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $best = null;
        foreach (self::KEYS as $key) {
            $m = $meta[$key] ?? null;
            if (! is_array($m) || ! isset($m['refreshed_at']) || ! is_string($m['refreshed_at'])) {
                continue;
            }
            if ($best === null || strcmp($m['refreshed_at'], (string) $best['mark']['refreshed_at']) > 0) {
                $best = ['key' => $key, 'mark' => $m];
            }
        }

        return $best;
    }

    /** When the refreshed content went live: `republished_at` once the push has stamped it, else the refresh itself. */
    public static function since(Content $post): ?Carbon
    {
        $latest = self::latest($post);
        if ($latest === null) {
            return null;
        }
        $m = $latest['mark'];
        $at = isset($m['republished_at']) && is_string($m['republished_at']) ? $m['republished_at'] : (string) $m['refreshed_at'];

        return Carbon::parse($at);
    }

    /**
     * Stamp `republished_at` on every mark whose refresh has not been republished yet (refreshed after the last
     * stamp, or never stamped). Called by the publish path on a successful push; returns whether anything changed.
     */
    public static function stampRepublished(Content $post, ?Carbon $at = null): bool
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $at ??= Carbon::now();
        $changed = false;
        foreach (self::KEYS as $key) {
            $m = $meta[$key] ?? null;
            if (! is_array($m) || ! isset($m['refreshed_at']) || ! is_string($m['refreshed_at'])) {
                continue;
            }
            $stamped = isset($m['republished_at']) && is_string($m['republished_at']) ? $m['republished_at'] : null;
            if ($stamped === null || strcmp($stamped, $m['refreshed_at']) < 0) {
                $meta[$key]['republished_at'] = $at->toIso8601String();
                $changed = true;
            }
        }
        if ($changed) {
            $post->forceFill(['meta' => $meta])->save();
        }

        return $changed;
    }
}
