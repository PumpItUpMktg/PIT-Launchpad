<?php

namespace App\Enums;

/**
 * The kind of question a GEO prompt asks — one of the ways real people query AI assistants about a home
 * service. Each intent is a different weakness lens and a different phrasing template. Geo intents pin a
 * market (city); non-geo intents are service- or brand-level (asked once, no city). Prompts are kept
 * **neutral / demand-shaped** (never "is {brand} the best?", which biases the answer) — the only
 * brand-named intent is Reviews (reputation), kept separate on purpose.
 */
enum GeoIntent: string
{
    case Hire = 'hire';
    case Cost = 'cost';
    case Emergency = 'emergency';
    case Comparison = 'comparison';
    case HowTo = 'how_to';
    case Reviews = 'reviews';

    /** Geo intents vary by market (city); non-geo ask once at the service/brand level. */
    public function isGeo(): bool
    {
        return match ($this) {
            self::Hire, self::Cost, self::Emergency, self::Comparison => true,
            self::HowTo, self::Reviews => false,
        };
    }

    /** Reviews names the brand (reputation) — skip it when the site has no brand name. */
    public function needsBrand(): bool
    {
        return $this === self::Reviews;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hire => 'Hire',
            self::Cost => 'Cost',
            self::Emergency => 'Emergency',
            self::Comparison => 'Comparison',
            self::HowTo => 'How-to',
            self::Reviews => 'Reviews',
        };
    }

    /**
     * Render the natural-language prompt for a service (+ place for geo intents, + brand for Reviews).
     */
    public function render(string $service, ?string $city = null, ?string $state = null, ?string $brand = null): string
    {
        $svc = mb_strtolower(trim($service));
        $place = trim((string) $city) !== ''
            ? (trim((string) $state) !== '' ? trim((string) $city).', '.trim((string) $state) : trim((string) $city))
            : '';

        return match ($this) {
            self::Hire => "Who is the best {$svc} company in {$place}?",
            self::Cost => "How much does {$svc} cost in {$place}?",
            self::Emergency => "I need emergency {$svc} in {$place} — who should I call?",
            self::Comparison => "What are the top-rated {$svc} companies near {$place}?",
            self::HowTo => "How do I handle {$svc} myself, and when should I hire a pro?",
            self::Reviews => 'Is '.trim((string) $brand)." a good choice for {$svc}? What do customers say?",
        };
    }
}
