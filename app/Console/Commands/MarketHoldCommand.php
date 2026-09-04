<?php

namespace App\Console\Commands;

use App\Models\Market;
use App\Operator\Coverage\MarketHold;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Set or lift an advisory hold on a market (the interim operator path until the Markets workspace ships
 * the in-panel control over the same {@see MarketHold} service). A hold is a reminder only — no publish
 * effect. Runs in a console context (no locked tenant), so it resolves the market across tenants.
 *
 *   launchpad:market-hold {market} --until=2026-10-01   # hold, targeting a release date
 *   launchpad:market-hold {market} --lift               # release (clears the hold)
 */
class MarketHoldCommand extends Command
{
    protected $signature = 'launchpad:market-hold {market : the Market id} {--until= : target release date (Y-m-d), required unless --lift} {--lift : lift the hold instead of setting one}';

    protected $description = 'Set or lift an advisory hold on a market (reminder only; no publish effect).';

    public function handle(MarketHold $holds): int
    {
        $market = Market::query()->find($this->argument('market'));
        if ($market === null) {
            $this->error('No market found for that id.');

            return self::FAILURE;
        }

        if ($this->option('lift')) {
            $holds->release($market);
            $this->info("Released the hold on {$market->name}.");

            return self::SUCCESS;
        }

        $until = $this->option('until');
        if (! is_string($until) || $until === '') {
            $this->error('Pass --until=<Y-m-d> to set a hold, or --lift to release one.');

            return self::FAILURE;
        }

        try {
            $releaseAt = Carbon::parse($until);
        } catch (\Throwable) {
            $this->error("Could not parse --until date: {$until}");

            return self::FAILURE;
        }

        $holds->hold($market, $releaseAt);
        $this->info("Held {$market->name} until {$releaseAt->toDateString()}.");

        return self::SUCCESS;
    }
}
