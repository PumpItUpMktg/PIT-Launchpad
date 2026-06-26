<?php

namespace App\Standard;

use App\Enums\StandardPageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\SiteNarrative;

/**
 * The explicit map decision #2 demands: for each composable standard page, WHICH brand-narrative
 * intake it needs and how it degrades when that intake is absent. Two tiers, both keyed to
 * {@see SiteNarrative} columns:
 *
 *  - **required** — the page is pointless without it (an About with no story). Absent → the page
 *    HOLDS "needs intake" ({@see GroundingReadiness}), never drafts. It must not fabricate the one
 *    thing that makes it that business's page.
 *  - **optional** — composed from when present, OMITTED when absent (the slot is conditioned out).
 *    Degrade by omission, never by invention.
 *
 * This is the anti-fabrication spine for Core pages: the composer only ever writes from captured
 * inputs + voice; what isn't captured is held or omitted, not invented.
 */
final class StandardPageIntake
{
    /** @var array<string, array{required: list<string>, optional: list<string>}> */
    private const MAP = [
        'home' => ['required' => [], 'optional' => ['story']],
        'about' => ['required' => ['story'], 'optional' => ['mission', 'values']],
        'why_choose_us' => ['required' => ['differentiators'], 'optional' => []],
        'faq' => ['required' => [], 'optional' => []],
    ];

    /** @return list<string> */
    public static function required(StandardPageType $type): array
    {
        return self::MAP[$type->value]['required'] ?? [];
    }

    /** @return list<string> */
    public static function optional(StandardPageType $type): array
    {
        return self::MAP[$type->value]['optional'] ?? [];
    }

    /** Every narrative field this page consumes (required + optional). @return list<string> */
    public static function fields(StandardPageType $type): array
    {
        return [...self::required($type), ...self::optional($type)];
    }

    /**
     * The required narrative fields a page is MISSING (empty list = satisfied). A page with no
     * standard_type or no required intake is always satisfied. Presence = a non-empty value on the
     * site's narrative row.
     *
     * @return list<string>
     */
    public static function missingRequired(Content $page): array
    {
        $type = $page->standard_type;
        if (! $type instanceof StandardPageType) {
            return [];
        }

        $required = self::required($type);
        if ($required === []) {
            return [];
        }

        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $page->site_id)
            ->first();

        return array_values(array_filter($required, fn (string $f) => ! self::present($narrative, $f)));
    }

    /** Whether a narrative field carries real content (non-empty string, or non-empty list). */
    public static function present(?SiteNarrative $narrative, string $field): bool
    {
        if ($narrative === null) {
            return false;
        }

        $value = $narrative->getAttribute($field);

        return is_array($value) ? $value !== [] : (is_string($value) && trim($value) !== '');
    }
}
