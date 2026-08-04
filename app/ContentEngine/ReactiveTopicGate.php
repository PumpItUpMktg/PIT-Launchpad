<?php

namespace App\ContentEngine;

use App\Integrations\News\NewsItem;

/**
 * The cheap, rule-based topical + geography gate for the REACTIVE (news) lane — it runs after {@see PreFilter}
 * and before any LLM relevance cost, and it is what keeps the buyer's intent tight. The Aug-4 batch published
 * six posts, five of them municipal-utility finance (sewer grants, water/sewer/trash bills, rate hikes) — not
 * a waterproofing buyer's intent, and topical-authority dilution. Two independent checks:
 *
 *  1. **Topical** — the item must hit at least one buyer-intent `allow` topic (flooding, groundwater, sump
 *     pumps, drainage, radon, …). A finance/governance term in the TITLE (`deny_context`) vetoes it even
 *     when an incidental water word appears in the body — a rate-hike headline is a finance story.
 *  2. **Geography** — a story anchored to a state OUTSIDE the tenant's footprint (a Lansing, Michigan item on
 *     an NJ/PA business) is dropped; it's a second path by which out-of-footprint towns enter copy. The
 *     out-of-footprint match requires the "City, State" dateline form (", Michigan") so a common in-state
 *     TOWN name (Washington NJ, Georgia) never false-drops real coverage — the safe direction.
 *
 * Allow/deny lists are config-driven (`launchpad.reactive.*`), tunable per the feed controls.
 */
final class ReactiveTopicGate
{
    /** @var list<string> */
    private array $allow;

    /** @var list<string> */
    private array $denyContext;

    /** US state FIPS-independent name lookup — NAMES only (abbrevs false-match too easily, e.g. "PA" in "Repair"). */
    private const STATE_NAMES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'FL' => 'Florida', 'GA' => 'Georgia',
        'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire',
        'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
        'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee',
        'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /**
     * @param  list<string>|null  $allow
     * @param  list<string>|null  $denyContext
     */
    public function __construct(?array $allow = null, ?array $denyContext = null)
    {
        $this->allow = $allow ?? config('launchpad.reactive.allow', []);
        $this->denyContext = $denyContext ?? config('launchpad.reactive.deny_context', []);
    }

    /**
     * The drop reason for a reactive item, or null when it passes the gate.
     *
     * @param  list<string>  $footprintStates  the tenant's own states, 2-letter upper-case (e.g. ["NJ", "PA"])
     * @return string|null 'off_topic' | 'out_of_footprint' | null
     */
    public function rejection(NewsItem $item, array $footprintStates): ?string
    {
        $text = mb_strtolower($item->text());
        $title = mb_strtolower($item->title);

        if (! $this->hitsAny($text, $this->allow)) {
            return 'off_topic';
        }
        if ($this->hitsAny($title, $this->denyContext)) {
            return 'off_topic'; // a finance/governance HEADLINE is a finance story, incidental water word or not
        }
        if ($this->outOfFootprint($text, $footprintStates)) {
            return 'out_of_footprint';
        }

        return null;
    }

    /** @param  list<string>  $needles */
    private function hitsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the item names a state OUTSIDE the footprint and gives no in-footprint signal. With an
     * unknown footprint we never guess (return false — keep the item).
     *
     * @param  list<string>  $footprintStates
     */
    private function outOfFootprint(string $text, array $footprintStates): bool
    {
        $footprint = array_values(array_filter(array_map(fn ($s): string => strtoupper(trim((string) $s)), $footprintStates)));
        if ($footprint === []) {
            return false;
        }

        // An in-footprint signal (the tenant's own state named) keeps the item regardless.
        foreach ($footprint as $abbr) {
            $name = self::STATE_NAMES[$abbr] ?? null;
            if ($name !== null && str_contains($text, mb_strtolower($name))) {
                return false;
            }
        }

        // Any OTHER state named in "City, State" dateline form → the story is anchored out of footprint.
        // Requiring the leading comma avoids false-dropping a common in-state TOWN name (Washington NJ,
        // Georgia) — the safe direction (never drop real coverage on a heuristic).
        foreach (self::STATE_NAMES as $abbr => $name) {
            if (! in_array($abbr, $footprint, true) && str_contains($text, ', '.mb_strtolower($name))) {
                return true;
            }
        }

        return false;
    }
}
