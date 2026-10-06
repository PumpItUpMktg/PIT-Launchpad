<?php

namespace App\Operator\Coverage;

use App\Models\Content;
use Illuminate\Support\Carbon;

/**
 * The index-rework brief (§ Indexing): what a page Google crawled and declined is told on its next draft,
 * carried on `meta.index_rework` from the moment the operator asks for the rework until the redraft lands
 * (then stamped `applied_at` so the board can say "reworked on …"). The brief is the one thing the drafter
 * reads differently on a rework: make the page substantially more specific and useful, not longer.
 *
 * `carry()` is the rule both drafting engines apply when they rebuild `meta` on a successful draft: the
 * keys that belong to the page's life, not to one draft — its priority sections and this brief — survive.
 */
final class IndexRework
{
    public const META_KEY = 'index_rework';

    /** Meta keys a redraft must keep (everything else is the draft's own and is rebuilt). */
    public const CARRY = ['priority_sections', 'priority_sections_at', self::META_KEY];

    /** @return array{brief: string, verdict: string, requested_at: string, applied_at: string|null}|null */
    public static function on(Content $content): ?array
    {
        $raw = is_array($content->meta) ? ($content->meta[self::META_KEY] ?? null) : null;
        if (! is_array($raw) || trim((string) ($raw['brief'] ?? '')) === '') {
            return null;
        }

        return [
            'brief' => (string) $raw['brief'],
            'verdict' => (string) ($raw['verdict'] ?? ''),
            'requested_at' => (string) ($raw['requested_at'] ?? ''),
            'applied_at' => isset($raw['applied_at']) ? (string) $raw['applied_at'] : null,
        ];
    }

    /** The brief still waiting for its redraft (requested, not yet applied). */
    public static function pending(Content $content): ?string
    {
        $rework = self::on($content);

        return $rework !== null && $rework['applied_at'] === null ? $rework['brief'] : null;
    }

    /** Ask for a rework: store the brief; the next draft reads it. */
    public static function request(Content $content, string $verdict, string $brief): void
    {
        $meta = is_array($content->meta) ? $content->meta : [];
        $meta[self::META_KEY] = ['brief' => trim($brief), 'verdict' => $verdict, 'requested_at' => Carbon::now()->toIso8601String(), 'applied_at' => null];
        $content->forceFill(['meta' => $meta])->save();
    }

    /**
     * The meta a successful draft persists: the draft's own keys, plus the page-life keys carried over from
     * the previous meta; a pending rework brief is stamped applied.
     *
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $fresh
     * @return array<string, mixed>
     */
    public static function carry(?array $previous, array $fresh): array
    {
        $previous ??= [];
        foreach (self::CARRY as $key) {
            if (array_key_exists($key, $previous) && ! array_key_exists($key, $fresh)) {
                $fresh[$key] = $previous[$key];
            }
        }
        if (is_array($fresh[self::META_KEY] ?? null) && ($fresh[self::META_KEY]['applied_at'] ?? null) === null) {
            $fresh[self::META_KEY]['applied_at'] = Carbon::now()->toIso8601String();
        }

        return $fresh;
    }

    /** The prompt block the drafters add when a brief is pending. */
    public static function promptBlock(string $brief, bool $isTown): string
    {
        return 'INDEX REWORK — Google crawled the previous version of this page and DECLINED to index it. This draft must '
            .'be substantially more specific and more useful than a generic page on the subject, not merely longer: lead '
            .'with concrete, verifiable material from the grounding (facts, figures, real local conditions, real jobs and '
            .'reviews where given), say what a reader could not get elsewhere, cut filler, and give every section a reason '
            .'to exist. '.($isTown ? 'This is a TOWN page: every section must say something true of THIS town specifically — '
            .'a sentence that would read the same on the next town over is the problem being fixed. ' : '')
            ."\nOperator's brief: ".trim($brief);
    }
}
