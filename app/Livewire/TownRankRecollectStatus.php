<?php

namespace App\Livewire;

use App\Jobs\RecollectTownRank;
use App\TownRank\TownRankRecollectProgress;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The Town rank board's re-read control: the "Re-read masked towns" button and the live progress line
 * ({@see TownRankRecollectProgress}). Its own component so that polling while a chain runs re-renders
 * THIS line only — a poll on the page itself re-rendered the whole 22-keyword board every few seconds
 * while the worker was busy on the same database, and the page timed out.
 */
class TownRankRecollectStatus extends Component
{
    public string $siteId = '';

    /** @return array{status: string, total: int, read: int, ranked: int, not_found: int, skipped: int, remaining: int, started_at: ?string, updated_at: ?string, finished_at: ?string, error: ?string, running: bool} */
    public function getProgressProperty(): array
    {
        return array_merge(TownRankRecollectProgress::get($this->siteId), ['running' => TownRankRecollectProgress::isRunning($this->siteId)]);
    }

    /** Queue the re-read of every phantom / masked town across the site's scans — free reads, in chunks, on the worker. */
    public function recollect(): void
    {
        if ($this->siteId === '') {
            return;
        }
        if (! RecollectTownRank::start($this->siteId)) {
            Notification::make()->warning()->title('Already re-reading')->body('A re-read is in progress for this site — the line updates as it goes.')->send();

            return;
        }
        Notification::make()->success()->title('Re-read queued')
            ->body('The worker counts the backlog first, then re-reads it in chunks — free reads from the stored task results. The cards update as ranks land.')->send();
    }

    public function render(): View
    {
        return view('livewire.town-rank-recollect-status');
    }
}
