<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GrowDashboard;
use App\Guided\GuidedPage;
use App\Interview\Arrange\AutoArrangeRunner;
use App\Jobs\BuildStructure;
use Filament\Notifications\Notification;

/**
 * Step 5 · Grow dashboard. Build stats, the (scaffold) town queue, the fresh-content feed
 * ({@see GrowDashboard}), and the re-run controls — re-ground volume / re-arrange, which reuse
 * the existing engine with the §10 decision-preservation twin (confirmed decisions survive).
 *
 * @property-read array{live: int, building: int, planned: int} $stats
 * @property-read array<int, array{title: string, status: string, silo: string}> $news
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

    /**
     * @return array{live: int, building: int, planned: int}
     */
    public function getStatsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? ['live' => 0, 'building' => 0, 'planned' => 0] : app(GrowDashboard::class)->stats($site);
    }

    /**
     * @return array<int, array{title: string, status: string, silo: string}>
     */
    public function getNewsProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? [] : app(GrowDashboard::class)->news($site);
    }

    /** Re-arrange the structure — confirmed decisions are preserved (§10 twin). */
    public function reArrange(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        app(AutoArrangeRunner::class)->run($site);
        Notification::make()->title('Re-arranged — your confirmed decisions were preserved.')->success()->send();
    }

    /** Re-ground volume (and re-arrange) on the queue; decisions preserved. */
    public function reGround(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        BuildStructure::dispatch($site->id);
        Notification::make()->title('Re-grounding volume — this runs in the background.')->success()->send();
    }
}
