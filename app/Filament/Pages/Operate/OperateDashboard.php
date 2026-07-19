<?php

namespace App\Filament\Pages\Operate;

use App\Filament\Pages\Gathering\BusinessStep;
use App\Filament\Resources\SourceResource;
use App\Operate\AttentionBoard;

/**
 * Operate · Dashboard — the operational home. Attention items only, cross-tenant: every tile is
 * work, every number click-throughs to the filtered surface, a clean tenant doesn't appear. The
 * operator works this to zero instead of remembering to check each site.
 *
 * @property-read array{totals: array<string, int>, rows: list<array<string, mixed>>} $board
 */
class OperateDashboard extends OperatePage
{
    protected static ?string $slug = 'operate/dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    // Nav-final: the root Dashboard is a top-level entry (Dashboard · Portfolio · Setup), not a
    // member of the Operate group — that group is the pages boards only.
    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = -30;

    protected string $view = 'filament.operate.dashboard';

    /**
     * @return array{totals: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function getBoardProperty(): array
    {
        return app(AttentionBoard::class)->build();
    }

    /** Where each attention item key click-throughs to, per tenant. */
    public function urlFor(string $key, string $siteId): string
    {
        return match ($key) {
            'review' => OperateBlog::getUrl(['site' => $siteId, 'tab' => 'review']),
            'candidates' => OperateBlog::getUrl(['site' => $siteId, 'tab' => 'candidates']),
            'failures' => OperateBlog::getUrl(['site' => $siteId, 'tab' => 'review']),
            'starved_queues' => OperateBlog::getUrl(['site' => $siteId, 'tab' => 'published', 'targets' => 1]),
            'stale_feeds' => SourceResource::getUrl('index'),
            'setup_gaps' => BusinessStep::getUrl(['site' => $siteId]),
            default => OperateBlog::getUrl(['site' => $siteId]),
        };
    }
}
