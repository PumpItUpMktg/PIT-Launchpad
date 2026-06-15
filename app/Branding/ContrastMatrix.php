<?php

namespace App\Branding;

/**
 * The WCAG contrast gate for a full brand palette (Phase 3). Checks the pairings the
 * base wf-* stylesheet actually renders:
 *   - body text on the page bg AND on the alt-tint bg   (normal text ≥ 4.5)
 *   - muted/secondary text on the page bg               (UI/large ≥ 3.0)
 *   - white CTA-button text on the accent               (≥ 4.5 — the conversion el.)
 * `evaluate()` is pure (the deterministic enforcer); the generator nudges the text
 * tier and hard-drops a candidate whose accent fails the button gate.
 */
final class ContrastMatrix
{
    public const TEXT_MIN = 4.5;

    public const UI_MIN = 3.0;

    public const BUTTON_TEXT = '#ffffff';

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
            ['pair' => 'button-text-on-accent', 'fg' => self::BUTTON_TEXT, 'bg' => $accent, 'min' => self::TEXT_MIN],
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

    /** Does the accent carry white CTA text at AA? The hard, un-nudgeable gate. */
    public static function accentPassesButton(string $accent): bool
    {
        return ColorContrast::ratio(self::BUTTON_TEXT, $accent) >= self::TEXT_MIN;
    }
}
