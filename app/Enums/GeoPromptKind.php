<?php

namespace App\Enums;

/**
 * The two GEO prompt lanes — they measure different things and are reported apart.
 *
 * - **Visibility** (the default, primary metric): a neutral, demand-shaped question ("best {service} in
 *   {town}") measuring whether the AI spontaneously cites the brand. This is the competitive-visibility
 *   signal; brand-anchored phrasing is deliberately kept out of it.
 * - **Coverage** (accuracy / knowledge check): a brand-anchored question ("does {brand} offer {service}
 *   in {town}?") that catches when the AI has WRONG or missing facts about a shop's service area. It is
 *   NOT a visibility number (naming the brand guarantees it's "cited"), so it is excluded from the cited%
 *   matrix and reported separately — a misinformation catcher whose fix is content/schema, not a blog post.
 */
enum GeoPromptKind: string
{
    case Visibility = 'visibility';
    case Coverage = 'coverage';

    public function label(): string
    {
        return match ($this) {
            self::Visibility => 'Visibility',
            self::Coverage => 'Coverage check',
        };
    }

    /** @return array<string, string> value => label, for filters. */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
