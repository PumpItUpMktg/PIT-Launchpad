{{-- The one-page activity report, shared by the operator page and the client portal (§ Activity).
     Expects: $report (ActivityLog::for), $progress (MonthlySnapshots::progress), $tab, $periodOptions,
     $period (wire:model), $clientView (bool). Printable: the controls hide, the page prints as one sheet. --}}
<style>
    .act { --a-line:#e2e7ee; --a-muted:#5a6675; --a-faint:#8a95a3; --a-surface:#ffffff; --a-surface2:#f6f8fb; }
    .dark .act { --a-line:#232c37; --a-muted:#9aa7b5; --a-faint:#6b7887; --a-surface:#0b1017; --a-surface2:#0f151c; }
    .act .a-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
    .act .a-bar select { font-size:13px; border:1px solid var(--a-line); border-radius:8px; padding:6px 10px; background:var(--a-surface); color:inherit; }
    .act .a-tabs { display:inline-flex; border:1px solid var(--a-line); border-radius:10px; overflow:hidden; }
    .act .a-tabs button { font-size:12.5px; padding:6px 14px; background:transparent; color:var(--a-muted); border:none; cursor:pointer; }
    .act .a-tabs button.on { background:rgba(37,99,235,.10); color:inherit; font-weight:600; }
    .act .a-print { font-size:12.5px; border:1px solid var(--a-line); border-radius:8px; padding:6px 12px; background:transparent; color:inherit; cursor:pointer; margin-left:auto; }
    .act .a-head { margin:0 0 12px; }
    .act .a-head h2 { font-size:18px; font-weight:800; margin:0; }
    .act .a-head .sub { font-size:12.5px; color:var(--a-muted); margin-top:2px; }
    .act .a-strip { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; margin-bottom:16px; }
    .act .a-stat { border:1px solid var(--a-line); border-radius:12px; background:var(--a-surface); padding:10px 12px; }
    .act .a-stat b { display:block; font-size:22px; font-weight:800; font-variant-numeric:tabular-nums; line-height:1.1; }
    .act .a-stat span { font-size:11.5px; color:var(--a-muted); }
    .act .a-cols { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(260px,1fr); gap:16px; align-items:start; }
    @media (max-width:900px){ .act .a-cols { grid-template-columns:1fr; } }
    .act .a-panel { border:1px solid var(--a-line); border-radius:14px; background:var(--a-surface); padding:14px; }
    .act .a-panel h3 { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--a-muted); font-weight:700; margin:0 0 10px; }
    .act .a-day { padding:8px 0; border-top:1px solid var(--a-line); }
    .act .a-day:first-of-type { border-top:none; padding-top:0; }
    .act .a-day .d { font-size:11.5px; color:var(--a-faint); font-weight:700; margin-bottom:3px; font-variant-numeric:tabular-nums; }
    .act .a-day ul { list-style:none; margin:0; padding:0; }
    .act .a-day li { font-size:13px; padding:2px 0; display:flex; gap:8px; align-items:baseline; }
    .act .a-day li i { width:7px; height:7px; border-radius:50%; background:#2563eb; flex:none; position:relative; top:-1px; }
    .act .a-day li.milestone i { background:#b45309; } .act .a-day li.live i { background:#15803d; } .act .a-day li.scan i, .act .a-day li.gbp_scan i, .act .a-day li.citations i, .act .a-day li.reviews i, .act .a-day li.launch i { background:#9ca3af; } .act .a-day li.event i { background:#7c3aed; }
    .act .a-empty { font-size:13px; color:var(--a-muted); padding:20px 0; text-align:center; }
    .act .a-metric { display:flex; justify-content:space-between; gap:10px; font-size:13px; padding:7px 0; border-bottom:1px solid var(--a-line); }
    .act .a-metric:last-child { border-bottom:none; }
    .act .a-metric small { display:block; color:var(--a-faint); font-size:11px; }
    .act .a-metric b { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .act .a-metric .d { font-size:12px; font-weight:700; margin-left:6px; }
    .act .up { color:#15803d; } .act .down { color:#c0392b; } .act .flat { color:var(--a-faint); }
    .act table.a-prog { width:100%; border-collapse:collapse; font-size:12.5px; }
    .act table.a-prog th { text-align:right; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--a-muted); padding:6px 8px; border-bottom:1px solid var(--a-line); white-space:nowrap; }
    .act table.a-prog th:first-child, .act table.a-prog td:first-child { text-align:left; }
    .act table.a-prog td { text-align:right; padding:6px 8px; border-bottom:1px solid var(--a-line); font-variant-numeric:tabular-nums; white-space:nowrap; }
    .act table.a-prog tr.open td { color:var(--a-muted); font-style:italic; }
    .act .a-spark { display:inline-block; vertical-align:middle; margin-left:6px; }
    .act .a-note { font-size:11.5px; color:var(--a-faint); margin-top:8px; }
    @media print {
        .act .a-bar, .fi-sidebar, .fi-topbar, .fi-main-ctn > header { display:none !important; }
        .act .a-panel, .act .a-stat { break-inside:avoid; border-color:#ccc; }
        .act .a-cols { grid-template-columns:1fr 1fr; }
    }
</style>

@php
    $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v);
    $delta = function ($d, $kind) {
        if ($d === null) return '';
        if ((float) $d == 0) return '<span class="d flat">=</span>';
        $cls = $d > 0 ? 'up' : 'down';
        return '<span class="d '.$cls.'">'.($d > 0 ? '▲' : '▼').number_format(abs((float) $d)).'</span>';
    };
    $headlineLabels = [
        'pages_published' => 'Pages published', 'posts_published' => 'Posts published', 'pages_refreshed' => 'Pages refreshed',
        'pages_indexed' => 'Pages indexed by Google', 'sections_drafted' => 'Towns given priority sections', 'jobs_captured' => 'Jobs captured',
        'town_scans' => 'Town searches scanned', 'gbp_scans' => 'GBP map-pack scans', 'citation_scans' => 'Citation scans', 'citations_found' => 'Listings found', 'leads' => 'Leads',
    ];
    $clientHidden = ['town_scans', 'gbp_scans', 'citation_scans'];
    $spark = function (array $values): string {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if (count($values) < 2) return '';
        $max = max(1, max($values)); $min = min($values); $range = max(1, $max - $min);
        $pts = [];
        foreach ($values as $i => $v) { $pts[] = round($i * (60 / (count($values) - 1)), 1).','.round(14 - (($v - $min) / $range) * 12, 1); }
        return '<svg class="a-spark" width="62" height="16" viewBox="0 0 62 16" aria-hidden="true"><polyline fill="none" stroke="#2563eb" stroke-width="1.5" points="'.implode(' ', $pts).'" /></svg>';
    };
@endphp

<div class="act">
    <div class="a-bar">
        @isset($siteOptions)
            @if (count($siteOptions) > 1)
                <select wire:model.live="siteId" aria-label="Site">
                    @foreach ($siteOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            @endif
        @endisset
        <div class="a-tabs">
            <button type="button" class="{{ $tab === 'log' ? 'on' : '' }}" wire:click="setTab('log')">Activity</button>
            <button type="button" class="{{ $tab === 'progress' ? 'on' : '' }}" wire:click="setTab('progress')">Progress by month</button>
        </div>
        @if ($tab === 'log')
            <select wire:model.live="period" aria-label="Period">
                @foreach ($periodOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
        @endif
        <button type="button" class="a-print" onclick="window.print()">Print</button>
    </div>

    @if ($report === null)
        <div class="a-empty">Select a site to see its activity.</div>
    @elseif ($tab === 'log')
        <div class="a-head">
            <h2>What was completed — {{ $report['period']['label'] }}</h2>
            <div class="sub">{{ \Illuminate\Support\Carbon::parse($report['period']['start'])->format('M j, Y') }} – {{ \Illuminate\Support\Carbon::parse($report['period']['end'])->format('M j, Y') }} · {{ $report['entries'] }} {{ $report['entries'] === 1 ? 'entry' : 'entries' }}</div>
        </div>

        <div class="a-strip">
            @foreach ($headlineLabels as $key => $label)
                @continue($clientView && in_array($key, $clientHidden, true))
                @continue(($report['headline'][$key] ?? 0) === 0 && $key !== 'pages_published' && $key !== 'leads')
                <div class="a-stat"><b>{{ number_format($report['headline'][$key] ?? 0) }}</b><span>{{ $label }}</span></div>
            @endforeach
        </div>

        <div class="a-cols">
            <div class="a-panel">
                <h3>Timeline</h3>
                @forelse ($report['timeline'] as $day)
                    <div class="a-day">
                        <div class="d">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, M j') }}</div>
                        <ul>
                            @foreach ($day['entries'] as $e)
                                <li class="{{ $e['kind'] }}"><i></i><span>{{ $e['summary'] }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <div class="a-empty">Nothing completed in this period.</div>
                @endforelse
            </div>
            <div class="a-panel">
                <h3>How the numbers moved</h3>
                @foreach ($report['metrics'] as $m)
                    <div class="a-metric">
                        <span>{{ $m['label'] }}<small>{{ $m['kind'] === 'as_of' ? 'start → end of period' : 'this period vs the one before' }}</small></span>
                        <b>{{ $fmt($m['start']) }} → {{ $fmt($m['end']) }}{!! $delta($m['delta'], $m['kind']) !!}</b>
                    </div>
                @endforeach
                <div class="a-note">Observed movement only — no attribution is claimed. A metric reads — until its source has data.</div>
            </div>
        </div>
    @else
        <div class="a-head">
            <h2>Progress by month</h2>
            <div class="sub">Each closed month is frozen the day it ends and never recomputed; the current month is live.</div>
        </div>
        <div class="a-panel" style="overflow:auto">
            @php
                $metricKeys = ['pages_live' => 'Pages live', 'pages_indexed' => 'Indexed', 'keywords_top10' => 'Page-1 kw', 'town_visibility' => 'Town vis.', 'impressions' => 'Impressions', 'clicks' => 'Clicks', 'leads' => 'Leads'];
                $countKeys = $clientView ? ['pages_published' => 'Published', 'pages_refreshed' => 'Refreshed', 'jobs_captured' => 'Jobs'] : ['pages_published' => 'Published', 'pages_refreshed' => 'Refreshed', 'town_scans' => 'Town scans', 'jobs_captured' => 'Jobs'];
            @endphp
            <table class="a-prog">
                <thead><tr><th>Month</th>@foreach ($countKeys as $l)<th>{{ $l }}</th>@endforeach @foreach ($metricKeys as $l)<th>{{ $l }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($progress as $row)
                        <tr class="{{ $row['open'] ? 'open' : '' }}">
                            <td>{{ $row['label'] }}{{ $row['open'] ? ' (open)' : '' }}</td>
                            @foreach ($countKeys as $k => $l)<td>{{ number_format($row['counts'][$k] ?? 0) }}</td>@endforeach
                            @foreach ($metricKeys as $k => $l)
                                @php($m = $row['metrics'][$k] ?? null)
                                <td>{{ $m === null ? '—' : $fmt($m['end']) }}{!! $m === null ? '' : $delta($m['delta'], $m['kind']) !!}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if (count($progress) > 1)
                    <tfoot><tr><th>Trend</th>@foreach ($countKeys as $k => $l)<th>{!! $spark(array_map(fn ($r) => $r['counts'][$k] ?? 0, $progress)) !!}</th>@endforeach @foreach ($metricKeys as $k => $l)<th>{!! $spark(array_map(fn ($r) => $r['metrics'][$k]['end'] ?? null, $progress)) !!}</th>@endforeach</tr></tfoot>
                @endif
            </table>
            <div class="a-note">Counts are the work done in the month; metrics are the value at month end with the movement over the month. Impressions, clicks and leads are the month's totals against the month before.</div>
        </div>
    @endif
</div>
