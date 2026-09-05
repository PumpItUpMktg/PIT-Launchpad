<?php

namespace App\Filament\Pages;

use App\Enums\GeoCheckAction;
use App\Enums\UserRole;
use App\Geo\GeoCheckStatus;
use App\Integrations\AiSearch\AiEngineRegistry;
use App\Models\GeoCheckEvent;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * AI Visibility · Live — the operator's live console for watching the GEO check contact the AI engines:
 * per-engine lanes with a "contacting now" cursor, a run progress header, and the streaming step feed
 * (measured / skipped-fresh / deferred / error). Reads the {@see GeoCheckStatus} flag + cursor and the
 * {@see GeoCheckEvent} activity log; polls so it fills in live while a run works. Operator-only.
 *
 * @property-read array<string, mixed>|null $console
 * @property-read array<string, string> $sites
 */
class GeoActivityConsole extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'AI Visibility · Live';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'geo-activity';

    protected string $view = 'filament.pages.geo-activity';

    /** Selected tenant. */
    public ?string $siteId = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** Menu-map family tag: a GEO operator surface whose final placement is pending (with the rest of GEO). */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** @return array<string, string> */

    /** @return array<string, mixed>|null */
    public function getConsoleProperty(): ?array
    {
        if ($this->siteId === null) {
            return null;
        }
        $siteId = $this->siteId;
        $status = app(GeoCheckStatus::class);
        $contacting = $status->currentContact($siteId);

        $latestRunId = GeoCheckEvent::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->latest('created_at')->value('run_id');
        $events = $latestRunId !== null
            ? GeoCheckEvent::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)->where('run_id', $latestRunId)
                ->latest('created_at')->with('prompt:id,prompt')->get()
            : collect();

        $enabledKeys = array_map(fn ($e): string => $e->key(), app(AiEngineRegistry::class)->enabled());
        $engineKeys = collect($enabledKeys)->merge($events->pluck('engine'))->filter()->unique()->values();

        $engines = $engineKeys->map(function (string $key) use ($events, $contacting): array {
            $rows = $events->where('engine', $key);

            return [
                'key' => $key,
                'name' => ucfirst($key),
                'measured' => $rows->where('action', GeoCheckAction::Measured)->count(),
                'cited' => $rows->where('action', GeoCheckAction::Measured)->where('cited', true)->count(),
                'skipped' => $rows->where('action', GeoCheckAction::SkippedFresh)->count(),
                'deferred' => $rows->where('action', GeoCheckAction::Deferred)->count(),
                'errors' => $rows->where('action', GeoCheckAction::Error)->count(),
                'contacting' => is_array($contacting) && $contacting['engine'] === $key,
            ];
        })->all();

        $activePrompts = GeoPrompt::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->where('active', true)->count();
        $engineCount = max(1, count($enabledKeys) ?: $engineKeys->count());

        // The engine's answer prose lives on GeoSnapshot (answer_excerpt), keyed by (prompt, engine);
        // pull the latest per pair so each measured feed row can print what the engine actually said.
        $promptIds = $events->pluck('geo_prompt_id')->filter()->unique()->values();
        $excerpts = $promptIds->isEmpty()
            ? collect()
            : GeoSnapshot::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)->whereIn('geo_prompt_id', $promptIds)
                ->latest('checked_at')->get(['geo_prompt_id', 'engine', 'answer_excerpt'])
                ->groupBy(fn (GeoSnapshot $s): string => $s->geo_prompt_id.'|'.$s->engine)
                ->map(fn ($group) => $group->first()->answer_excerpt);

        return [
            'running' => $status->isRunning($siteId),
            'started_at' => $status->startedAt($siteId),
            'contacting' => $contacting,
            'engines' => $engines,
            'measured' => $events->where('action', GeoCheckAction::Measured)->count(),
            'total' => $activePrompts * $engineCount,
            // Surface the answer-bearing steps first: a budget-capped run can end in a long tail of
            // Deferred rows that would otherwise fill the newest-30 window and bury every measured/fresh
            // step. Rank measured+fresh ahead of error ahead of deferred; events arrive newest-first and
            // PHP's sort is stable, so newest-first is preserved within each rank.
            'feed' => $events->sortBy(fn (GeoCheckEvent $e): int => match ($e->action) {
                GeoCheckAction::Measured, GeoCheckAction::SkippedFresh => 0,
                GeoCheckAction::Error => 1,
                GeoCheckAction::Deferred => 2,
            })->take(30)->map(fn (GeoCheckEvent $e): array => [
                'town' => $e->town,
                'engine' => $e->engine,
                'action' => $e->action->label(),
                'color' => $e->action->color(),
                'is_measured' => $e->action === GeoCheckAction::Measured,
                'cited' => $e->cited,
                'competitors' => $e->competitors ?? [],
                'prompt' => data_get($e->prompt, 'prompt'),
                // Print the engine's answer for a measured step AND for a skipped-fresh one (its cached
                // snapshot answer is still the current answer); deferred/error steps have none to show.
                'answer' => in_array($e->action, [GeoCheckAction::Measured, GeoCheckAction::SkippedFresh], true)
                    ? $excerpts->get($e->geo_prompt_id.'|'.$e->engine)
                    : null,
            ])->all(),
        ];
    }
}
