<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Operate\QueueHealth;
use App\Operate\WorkerHeartbeat;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Queue (System) — what the background workers are doing, right now, in the workers' own words. One row
 * per lane (queue name) with its backlog and the live worker polling it; one row per worker process with
 * its heartbeat, the job it is on, its tally, and how it stopped if it is gone. Read from the queue
 * tables + the `queue_workers` heartbeats ({@see WorkerHeartbeat}); polled every ten
 * seconds. Global — the queue serves every tenant — so no site lock. Operator-only.
 *
 * @property-read array<string, mixed> $health
 */
class QueueBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Queue';

    protected static ?string $title = 'Queue';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $slug = 'queue';

    protected string $view = 'filament.pages.queue-board';

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array<string, mixed> */
    public function getHealthProperty(): array
    {
        $health = app(QueueHealth::class);
        $snapshot = $health->snapshot();
        $snapshot['workers'] = $health->workers();
        $snapshot['failures'] = $snapshot['failed'] > 0 ? $health->failures() : [];

        return $snapshot;
    }

    /** The banner's "Clear failed" (queue:flush) — same action the boards carry. */
    public function clearFailedJobs(): void
    {
        $cleared = app(QueueHealth::class)->clearFailed();

        Notification::make()
            ->{$cleared > 0 ? 'success' : 'info'}()
            ->title($cleared > 0 ? "Cleared {$cleared} failed job(s)" : 'No failed jobs to clear')
            ->send();
    }
}
