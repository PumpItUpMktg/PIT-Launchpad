<?php

namespace App\ContentEngine\Drafting;

use Illuminate\Support\Str;

/**
 * Repairs an over-length drafted text slot to its kit `max_length` instead of failing
 * the whole page. LLM drafts routinely overshoot a char cap by a little (a 221-char
 * subhead against a 220 max) — hard-rejecting the draft over that is far too brittle,
 * so the page-drafting acceptance clamps first, then validates.
 *
 * Structure-preserving: a block body ({@see Str::markdown} → `<p>`
 * paragraphs) keeps WHOLE paragraphs while they fit (inline `<strong>`/links intact);
 * only when even the first paragraph overruns is its text truncated at a sentence — then
 * word — boundary. Inline/plain text (short_text) truncates the same way. The result is
 * always ≤ max and never a dangling tag. Idempotent: an in-bounds value returns unchanged.
 */
final class SlotLengthClamp
{
    public static function clamp(string $value, int $max): string
    {
        $value = trim($value);
        if ($max <= 0 || mb_strlen($value) <= $max) {
            return $value;
        }

        // Block body: keep as many whole <p> paragraphs as fit (preserves inline HTML).
        if (preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $value, $m) && $m[0] !== []) {
            $kept = '';
            foreach ($m[0] as $para) {
                $candidate = $kept === '' ? $para : $kept."\n".$para;
                if (mb_strlen($candidate) <= $max) {
                    $kept = $candidate;

                    continue;
                }
                break;
            }
            if ($kept !== '') {
                return $kept;
            }

            // Even the first paragraph overruns → truncate its inner text, re-wrap in its tag.
            $open = preg_match('/^<p\b[^>]*>/i', $m[0][0], $om) ? $om[0] : '<p>';
            $inner = (string) preg_replace('/^<p\b[^>]*>|<\/p>$/i', '', $m[0][0]);
            $text = self::truncateText(strip_tags($inner), $max - mb_strlen($open) - 4);

            return $text === '' ? '' : $open.$text.'</p>';
        }

        // Inline / plain text → boundary truncation (tags stripped so a cut can't dangle).
        return self::truncateText(strip_tags($value), $max);
    }

    /**
     * Truncate plain text to $max, preferring the last sentence end (that keeps most of the
     * budget), else the last word boundary. Never adds characters; never exceeds $max.
     */
    private static function truncateText(string $text, int $max): string
    {
        $text = trim($text);
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);

        // A sentence end (. ! ?) that still keeps at least half the budget reads cleanest — a whole
        // sentence beats a mid-sentence word cut. Below that we'd waste too much, so fall to a word.
        if (preg_match('/^.*[.!?](?=\s|$)/us', $cut, $sm) && mb_strlen($sm[0]) >= (int) ($max * 0.5)) {
            return rtrim($sm[0]);
        }

        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n-–—|:,;");
    }
}
