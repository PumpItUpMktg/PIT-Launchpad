<?php

namespace App\Activity;

use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use Illuminate\Support\Carbon;

/**
 * Freezes each closed month of a site's activity into {@see SiteMonthlySnapshot} (§ Activity): the headline
 * counts and the metric movement the one-page report shows, written once per month and never recomputed,
 * so the long-term progress record cannot drift when old data is pruned, a scan re-runs or a formula
 * changes. `backfill()` writes every past month from the records that exist, so the history starts from the
 * site's first month, not from today. `progress()` is the long-term view: every closed month plus the open
 * one, live.
 */
final class MonthlySnapshots
{
    public function __construct(private readonly ActivityLog $log) {}

    /** Freeze one month (first-of-month date). An already-closed month is left as it is unless $rebuild. */
    public function close(Site $site, Carbon $month, bool $rebuild = false): ?SiteMonthlySnapshot
    {
        $period = ActivityPeriod::month($month->format('Y-m'));
        if (! $period->isClosed()) {
            return null;
        }
        $existing = SiteMonthlySnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereDate('month', $period->start->toDateString())->first();
        if ($existing !== null && ! $rebuild) {
            return $existing;
        }
        $report = $this->log->for($site, $period);
        $values = [
            'counts' => $report['headline'],
            'metrics' => $report['metrics'],
            'timeline_entries' => $report['entries'],
            'closed_at' => Carbon::now(),
        ];
        if ($existing !== null) {
            $existing->forceFill($values)->save();

            return $existing->refresh();
        }

        return SiteMonthlySnapshot::withoutGlobalScope(SiteScope::class)->create($values + ['site_id' => $site->id, 'month' => $period->start->toDateString()]);
    }

    /**
     * Freeze every closed month from the site's first month (its creation) that has no snapshot yet.
     *
     * @return list<string> the months written (Y-m)
     */
    public function backfill(Site $site): array
    {
        $cursor = Carbon::parse((string) $site->created_at)->startOfMonth();
        $last = Carbon::now()->startOfMonth()->subMonthNoOverflow();
        $have = SiteMonthlySnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->get(['month'])
            ->map(fn (SiteMonthlySnapshot $s): string => $s->month->format('Y-m'))
            ->flip();
        $written = [];
        while ($cursor->lte($last)) {
            if (! $have->has($cursor->format('Y-m'))) {
                $this->close($site, $cursor->copy());
                $written[] = $cursor->format('Y-m');
            }
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $written;
    }

    /**
     * The long-term view: every closed month (frozen) plus the current month (live, marked open), oldest
     * first.
     *
     * @return list<array{month: string, label: string, open: bool, counts: array<string, int>, metrics: array<string, array{label: string, start: int|float|null, end: int|float|null, delta: int|float|null, kind: string}>, entries: int, closed_at: string|null}>
     */
    public function progress(Site $site): array
    {
        $out = [];
        foreach (SiteMonthlySnapshot::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('month')->get() as $s) {
            $out[] = [
                'month' => $s->month->format('Y-m'),
                'label' => $s->month->format('M Y'),
                'open' => false,
                'counts' => $s->counts,
                'metrics' => $s->metrics,
                'entries' => (int) $s->timeline_entries,
                'closed_at' => $s->closed_at->toIso8601String(),
            ];
        }
        $current = ActivityPeriod::month(Carbon::now()->format('Y-m'));
        $live = $this->log->for($site, $current);
        $out[] = [
            'month' => $current->key,
            'label' => $current->start->format('M Y'),
            'open' => true,
            'counts' => $live['headline'],
            'metrics' => $live['metrics'],
            'entries' => $live['entries'],
            'closed_at' => null,
        ];

        return $out;
    }
}
