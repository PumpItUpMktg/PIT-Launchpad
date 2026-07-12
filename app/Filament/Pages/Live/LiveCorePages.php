<?php

namespace App\Filament\Pages\Live;

use App\Guided\LiveBoardPage;
use App\Guided\LiveBoards;

/** LIVE · Core pages — the published home + standard pages with their tracking blocks. */
class LiveCorePages extends LiveBoardPage
{
    protected static ?string $navigationLabel = 'Core pages';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.live.core';

    public function getTitle(): string
    {
        return 'Live · Core pages';
    }

    /** @return list<array<string, mixed>> */
    public function getCardsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(LiveBoards::class)->core($site);
    }

    /** @return array{serp: bool, gsc: bool, ga: bool} */
    public function getSourcesProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? ['serp' => false, 'gsc' => false, 'ga' => false] : app(LiveBoards::class)->sources($site);
    }
}
