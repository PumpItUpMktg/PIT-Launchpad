<?php

namespace App\Branding;

/**
 * The WCAG contrast gate for a full brand palette (Phase 3). Checks the pairings the
 * base wf-* stylesheet actually renders:
 *   - body text on the page bg AND on the alt-tint bg   (normal text ≥ 4.5)
 *   - muted/secondary text on the page bg               (UI/large ≥ 3.0)
 *   - the CTA button text on the accent                 (≥ 4.5 — the conversion el.)
 *
 * The CTA text is NOT assumed white: `onAccent()` picks white-or-dark per accent for
 * max contrast (mirrored by the `--wf-color-on-accent` token), so a LIGHT accent
 * gets dark text and passes instead of dropping. A candidate only fails the button
 * gate when even the better of white/dark can't reach AA (a genuine mid-tone accent).
 */
final class ContrastMatrix
{
    public const TEXT_MIN = 4.5;

    public const UI_MIN = 3.0;

    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#1a1a1a';

    /**
     * @param  array<string, string>  $palette  SiteBranding keys (text/text_muted/bg/bg_alt/accent…)
     * @return list<array{pair: string, ratio: float, min: float}> the FAILURES (empty = all pass)
     */
    public static function failures(array $palette): array
    {
        $text = $palette['text'] ?? '#1a1a1a';
        $muted = $palette['text_muted'] ?? $text;
        $bg = $palette['bg'] ?? '#ffffff';
        $bgAlt = $palette['bg_alt'] ?? $bg;
        $accent = $palette['accent'] ?? '#000000';

        $checks = [
            ['pair' => 'text-on-bg', 'fg' => $text, 'bg' => $bg, 'min' => self::TEXT_MIN],
            ['pair' => 'text-on-bg_alt', 'fg' => $text, 'bg' => $bgAlt, 'min' => self::TEXT_MIN],
            ['pair' => 'text_muted-on-bg', 'fg' => $muted, 'bg' => $bg, 'min' => self::UI_MIN],
            // The CTA text is auto-chosen for the accent — gate the BEST option.
            ['pair' => 'button-text-on-accent', 'fg' => self::onAccent($accent), 'bg' => $accent, 'min' => self::TEXT_MIN],
        ];

        $failures = [];
        foreach ($checks as $check) {
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
