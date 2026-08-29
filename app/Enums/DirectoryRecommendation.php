<?php

namespace App\Enums;

/**
 * The operator-facing verdict on a directory (§ Citations, PR5): is it worth pursuing? Derived from computed
 * SEO value + cost, so the work-order queue can lead with the directories that move the needle and skip the
 * paid ones that don't pay for themselves.
 */
enum DirectoryRecommendation: string
{
    case MustHave = 'must_have';       // free + high value — always pursue
    case Recommended = 'recommended';  // free + moderate value
    case WorthPaying = 'worth_paying'; // paid, but the cost-per-value-point clears the bar
    case LowValue = 'low_value';       // free but little SEO value — backlog
    case SkipPaid = 'skip_paid';       // paid and not worth it

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** Worth putting in front of a VA / operator as actionable work. */
    public function isActionable(): bool
    {
        return in_array($this, [self::MustHave, self::Recommended, self::WorthPaying], true);
    }
}
