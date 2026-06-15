<?php

namespace App\Branding;

/**
 * The WCAG contrast gate for a full brand palette. The validated pairing list is the
 * SINGLE SOURCE OF TRUTH for every foreground-on-background pair the wf-* stylesheet
 * (and the picker preview) actually renders — `failures()` checks exactly the §3
 * surface model, so a palette that passes the gate cannot fail in a real placement:
 *   - body text on bg AND bg_alt                 (normal text ≥ 4.5)
 *   - muted text on bg AND bg_alt                (UI/large ≥ 3.0)
 *   - the CTA button text on the accent          (≥ 4.5 — the conversion el.)
 *
 * Heading/label foregrounds use `--wf-color-text` (a gated pair), never the brand
 * `primary` (a brand hue chosen for identity, not contrast) — so `primary` is a fill/
 * accent, never a readable-text pair the gate must police.
 *
 * Scheme-agnostic: it validates whatever colors the palette carries, so the same gate
 * proves a Light palette (dark text on light bg) and a Dark palette (light text on
 * dark bg). The CTA text is NOT assumed white — `onAccent()` picks white-or-dark per
 * accent (the `--wf-color-on-accent` token), so a light accent gets dark text and
 * passes; the button gate only fails for a genuine mid-tone accent.
 */
final class ContrastMatrix
{
    public const TEXT_MIN = 4.5;

    public const UI_MIN = 3.0;

    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#1a1a1a';

    /**
     * The pairing list the stylesheet renders: [pair label, fg token, bg token, min].
     * Tokens resolve from the palette (with sensible fallbacks). This is the contract
     * the CSS (§3) and the preview (§5) must mirror.
     *
     * @param  array<string, string>  $palette
     * @return list<array{pair: string, fg: string, bg: string, min: float}>
     */
    public static function pairings(array $palette): array
    {
        $text = $palette['text'] ?? '#1a1a1a';
        $muted = $palette['text_muted'] ?? $text;
        $bg = $palette['bg'] ?? '#ffffff';
        $bgAlt = $palette['bg_alt'] ?? $bg;
        $accent = $palette['accent'] ?? '#000000';

        return [
            ['pair' => 'text-on-bg', 'fg' => $text, 'bg' => $bg, 'min' => self::TEXT_MIN],
            ['pair' => 'text-on-bg_alt', 'fg' => $text, 'bg' => $bgAlt, 'min' => self::TEXT_MIN],
            ['pair' => 'text_muted-on-bg', 'fg' => $muted, 'bg' => $bg, 'min' => self::UI_MIN],
            ['pair' => 'text_muted-on-bg_alt', 'fg' => $muted, 'bg' => $bgAlt, 'min' => self::UI_MIN],
            // The CTA text is auto-chosen for the accent — gate the BEST option.
            ['pair' => 'button-text-on-accent', 'fg' => self::onAccent($accent), 'bg' => $accent, 'min' => self::TEXT_MIN],
        ];
    }

    /**
     * @param  array<string, string>  $palette  SiteBranding keys (text/text_muted/bg/bg_alt/accent…)
     * @return list<array{pair: string, ratio: float, min: float}> the FAILURES (empty = all pass)
     */
    public static function failures(array $palette): array
    {
        $failures = [];
        foreach (self::pairings($palette) as $check) {
            $ratio = ColorContrast::ratio($check['fg'], $check['bg']);
            if ($ratio < $check['min']) {
                $failures[] = ['pair' => $check['pair'], 'ratio' => round($ratio, 2), 'min' => $check['min']];
            }
        }

        return $failures;
    }

    /**
     * The CTA text color for an accent: white or a dark neutral, whichever has the
     * higher contrast (so a light accent gets dark text). This is the value the
     * `--wf-color-on-accent` token carries to the stylesheet.
     */
    public static function onAccent(string $accent): string
    {
        return ColorContrast::ratio(self::LIGHT_TEXT, $accent) >= ColorContrast::ratio(self::DARK_TEXT, $accent)
            ? self::LIGHT_TEXT
            : self::DARK_TEXT;
    }

    /**
     * Can the accent carry readable CTA text at AA with its best (white-or-dark) text
     * color? Only false for a genuine mid-tone accent neither passes.
     */
    public static function accentPassesButton(string $accent): bool
    {
        return ColorContrast::ratio(self::onAccent($accent), $accent) >= self::TEXT_MIN;
    }
}
