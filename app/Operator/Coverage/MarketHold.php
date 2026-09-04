<?php

namespace App\Operator\Coverage;

use App\Models\Market;
use Carbon\CarbonInterface;

/**
 * The single set/release path for an advisory market hold. A hold is a reminder only — it does NOT
 * affect publishing. `release_at` is the target release date; releasing is always a manual operator
 * action ({@see release()}), so a held market whose `release_at` has passed is "overdue" and surfaces
 * on the operator lobby as a Tier-2 badge ({@see Market::releaseOverdue()}).
 *
 * Every operator surface (the CLI today, the Markets workspace in a later PR) routes through here so
 * the semantics live in one place.
 */
class MarketHold
{
    /** Put a market on hold with a target release date. */
    public function hold(Market $market, CarbonInterface $releaseAt): Market
    {
        $market->forceFill(['on_hold' => true, 'release_at' => $releaseAt])->save();

        return $market;
    }

    /** Lift a hold — clears both the flag and the target date. */
    public function release(Market $market): Market
    {
        $market->forceFill(['on_hold' => false, 'release_at' => null])->save();

        return $market;
    }
}
