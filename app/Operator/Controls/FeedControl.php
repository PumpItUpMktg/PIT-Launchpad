<?php

namespace App\Operator\Controls;

use App\Models\Source;

/**
 * §6a feed control: enable/disable a tenant's news/source feeds. (Add/remove is
 * the Filament resource's create/delete; the backfill window + steady-state
 * freshness cutoff are the §6a 90-day tunables, carried on the source config.)
 */
class FeedControl
{
    public function enable(Source $source): Source
    {
        $source->forceFill(['enabled' => true])->save();

        return $source;
    }

    public function disable(Source $source): Source
    {
        $source->forceFill(['enabled' => false])->save();

        return $source;
    }
}
