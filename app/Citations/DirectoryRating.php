<?php

namespace App\Citations;

use App\Enums\DirectoryRecommendation;
use App\Models\Directory;
use App\Models\DirectoryMarketSignal;
use Illuminate\Support\Carbon;

/**
 * Rates a directory (§ Citations, PR5): a computed 0–100 SEO value and an operator verdict (must-have /
 * recommended / worth-paying / low-value / skip-paid). Value is objective — driven by domain rank (authority
 * tier as a fallback), discounted for nofollow, and overridden per market when a directory actually ranks
 * locally. The verdict folds in cost via cost-per-value-point, so the work queue can lead with the directories
 * that matter and skip the paid ones that don't pay for themselves.
 */
final class DirectoryRating
{
    /** Compute the 0–100 SEO value, honoring a per-market override when a geo is given. */
    public function seoValue(Directory $directory, ?string $geo = null): int
    {
        if ($geo !== null) {
            $local = $directory->marketSignals->firstWhere('geo_value', $geo)?->seo_value_local;
            if ($local !== null) {
                return $this->clamp((int) $local);
            }
        }

        // domain_rank is the objective signal; authority_tier (1–5 → 20–100) is the fallback.
        $base = $directory->domain_rank ?? ($directory->authority_tier * 20);
        if ($directory->is_nofollow) {
            $base = (int) round($base * 0.6); // a nofollow citation passes less SEO value
        }

        return $this->clamp($base);
    }

    /** The operator verdict, folding computed value together with cost. */
    public function recommendation(Directory $directory, ?string $geo = null): DirectoryRecommendation
    {
        $value = $this->seoValue($directory, $geo);
        $isFree = $directory->cost_amount === null || (float) $directory->cost_amount <= 0.0;

        if ($isFree) {
            return match (true) {
                $value >= 60 => DirectoryRecommendation::MustHave,
                $value >= 30 => DirectoryRecommendation::Recommended,
                default => DirectoryRecommendation::LowValue,
            };
        }

        // Paid: worth it only when both the value is real and the cost per point clears the ceiling.
        // Cost-per-point uses the freshly computed value so the verdict is internally consistent.
        $ceiling = (float) config('launchpad.citations.paid_value_point_ceiling', 0.5);
        $costPerPoint = $value > 0 ? round((float) $directory->cost_amount / $value, 2) : null;
        if ($value >= 30 && $costPerPoint !== null && $costPerPoint <= $ceiling) {
            return DirectoryRecommendation::WorthPaying;
        }

        return DirectoryRecommendation::SkipPaid;
    }

    /** Persist the computed global SEO value onto the directory. Returns the value. */
    public function rate(Directory $directory): int
    {
        $value = $this->seoValue($directory);
        $directory->forceFill(['seo_value' => $value])->save();

        return $value;
    }

    /**
     * Compute + persist a market's local SEO value from its ranking signals: a directory that actually ranks
     * for local terms is worth more in that market, best positions worth the most, crowded markets a little
     * less. Returns the value.
     */
    public function rateMarket(DirectoryMarketSignal $signal): int
    {
        $base = $this->seoValue($signal->directory);

        if (! $signal->ranks_for_local_terms) {
            $value = (int) round($base * 0.5); // present in the catalog but not ranking here — half value
        } else {
            $bestPosition = $this->bestPosition($signal);
            $boost = match (true) {
                $bestPosition !== null && $bestPosition <= 3 => 30,
                $bestPosition !== null && $bestPosition <= 10 => 15,
                default => 5,
            };
            $crowdPenalty = ($signal->competitor_count ?? 0) > 20 ? 10 : 0;
            $value = $base + $boost - $crowdPenalty;
        }

        $value = $this->clamp($value);
        $signal->forceFill(['seo_value_local' => $value, 'last_evaluated_at' => Carbon::now()])->save();

        return $value;
    }

    /** The best (lowest) SERP position across a market signal's recorded local terms, or null. */
    private function bestPosition(DirectoryMarketSignal $signal): ?int
    {
        $positions = array_values(array_filter(array_map(
            fn (array $row): int => (int) $row['position'],
            $signal->local_serp_positions ?? [],
        ), fn (int $p): bool => $p > 0));

        return $positions === [] ? null : min($positions);
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
