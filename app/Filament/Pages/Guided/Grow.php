<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;

/**
 * Step 5 · Grow dashboard. Build/drip stats, the town queue, the fresh-content feed, and
 * re-run controls (re-ground volume / re-arrange — decisions preserved). (Dashboard data +
 * controls land in the next layer; the spine wires the gated shell.)
 */
class Grow extends GuidedPage
{
    protected static ?string $slug = 'grow';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Grow';

    protected string $view = 'filament.guided.grow';

    public function step(): SetupStep
    {
        return SetupStep::Grow;
    }
}
