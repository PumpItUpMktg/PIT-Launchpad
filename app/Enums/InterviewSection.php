<?php

namespace App\Enums;

/**
 * The five coverage goals the adaptive interview must satisfy (gathering relay). Goals, not a
 * script — the engine tags each assistant turn with the section it is probing, skips satisfied
 * goals, and circles back to thin ones. The coverage meter renders one row per case.
 */
enum InterviewSection: string
{
    case Trust = 'trust';
    case Services = 'services';
    case Coverage = 'coverage';
    case MarketNotes = 'market_notes';
    case Voice = 'voice';

    public function label(): string
    {
        return match ($this) {
            self::Trust => 'Trust facts',
            self::Services => 'Services',
            self::Coverage => 'Coverage',
            self::MarketNotes => 'Market notes',
            self::Voice => 'Voice cues',
        };
    }

    /** What the conversation needs to get out of this section — drives the engine prompt. */
    public function goal(): string
    {
        return match ($this) {
            self::Trust => 'License number, insured status, years in business, warranty program, guarantees they make.',
            self::Services => 'An exhaustive stated-services list (including zero-search-volume work), plus per service: what is included, the process, cost drivers, and price ranges if they offer them.',
            self::Coverage => 'The towns each location serves, in the owner\'s own words — fuzzy answers are fine ("30 minutes from the shop, down to Phoenixville").',
            self::MarketNotes => 'Per-location local knowledge only the owner has — soil, housing stock, neighborhoods, seasonal patterns, local competitors.',
            self::Voice => 'How they talk: phrasing they use, claims they make, words they would never use.',
        };
    }
}
