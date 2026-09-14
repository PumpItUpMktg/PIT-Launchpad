<x-filament-panels::page>
@php
    $board = $this->board;
    $keywords = $this->keywords;
    $town = $this->town;
    $rows = $this->visibleRows;
    $isLocal = $board !== null && $board['mode'] === \App\Models\TownRankScan::MODE_LOCAL;
    $prefix = $isLocal ? 'local' : 'town';
    $dotR = function (array $markers): callable {
        $max = max(1, (int) collect($markers)->max('population'));
        return fn (int $pop): float => round(1.4 + sqrt(max(0, $pop) / $max) * 2.4, 2);
    };
    $rankCell = fn ($rank, string $state): string => match ($state) {
        'unscanned' => '',
        'pending' => '…',
        'not_found' => 'not found',
        default => '#'.(int) $rank,
    };
    $levelColor = fn (string $level): string => match ($level) { 'do' => '#c0392b', 'watch' => '#ca8a04', default => '#15803d' };
@endphp

<style>
    .trk { --t-line:#e2e7ee; --t-muted:#5a6675; --t-faint:#8a95a3; --t-surface:#ffffff; --t-surface2:#f6f8fb; }
    .dark .trk { --t-line:#232c37; --t-muted:#9aa7b5; --t-faint:#6b7887; --t-surface:#0b1017; --t-surface2:#0f151c; }
    .trk .t-chips { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; align-items:center; }
    .trk .t-chip { font-size:12px; border:1px solid var(--t-line); border-radius:999px; padding:6px 12px; background:transparent; color:var(--t-muted); cursor:pointer; }
    .trk .t-chip.on { border-color:#2563eb; color:inherit; background:rgba(37,99,235,.08); font-weight:600; }
    .trk .t-modes { display:inline-flex; border:1px solid var(--t-line); border-radius:8px; overflow:hidden; margin-left:auto; }
    .trk .t-modes button { font-size:12px; padding:6px 12px; background:transparent; color:var(--t-muted); border:none; cursor:pointer; }
    .trk .t-modes button.on { background:rgba(37,99,235,.10); color:inherit; font-weight:600; }
    .trk .t-empty { padding:44px 16px; text-align:center; color:var(--t-muted); border:1px dashed var(--t-line); border-radius:12px; }
    .trk .t-buckets { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:10px; margin-bottom:16px; }
    @media (max-width:820px){ .trk .t-buckets { grid-template-columns:repeat(3, minmax(0,1fr)); } }
    .trk .t-bucket { border:1px solid var(--t-line); border-radius:12px; padding:10px 12px; background:var(--t-surface); }
    .trk .t-bucket .n { font-size:24px; font-weight:800; line-height:1.1; font-variant-numeric:tabular-nums; }
    .trk .t-bucket .lab { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--t-muted); font-weight:700; margin-top:2px; }
    .trk .t-main { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(260px,1fr); gap:20px; align-items:start; }
    @media (max-width:820px){ .trk .t-main { grid-template-columns:1fr; } }
    .trk .t-mapwrap { border:1px solid var(--t-line); border-radius:14px; background:var(--t-surface2); padding:10px; }
    .trk .t-map { width:100%; height:auto; display:block; aspect-ratio:1/1; }
    .trk .t-dot { stroke:rgba(0,0,0,.25); stroke-width:.4; cursor:pointer; }
    .trk .t-dot.sel { stroke:#2563eb; stroke-width:1.2; }
    .trk .t-dot.nopage { stroke-dasharray:1 .6; }
    .trk .t-legend { display:flex; gap:12px; flex-wrap:wrap; font-size:11px; color:var(--t-muted); margin-top:8px; }
    .trk .t-legend i { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px; vertical-align:middle; }
    .trk .t-panel { border:1px solid var(--t-line); border-radius:14px; padding:14px; background:var(--t-surface); }
    .trk .t-panel h3 { font-size:15px; font-weight:800; margin:0 0 2px; }
    .trk .t-panel .sub { font-size:12px; color:var(--t-muted); margin-bottom:10px; }
    .trk .t-krow { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; padding:5px 0; border-bottom:1px solid var(--t-line); }
    .trk .t-krow:last-child { border-bottom:none; }
    .trk .t-krow b { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .trk .t-act { border-left:3px solid; padding:6px 10px; margin-top:8px; font-size:12.5px; background:var(--t-surface2); border-radius:0 8px 8px 0; }
    .trk .t-act b { display:block; }
    .trk .t-act span { color:var(--t-muted); }
    .trk .t-comp { font-size:12px; color:var(--t-muted); margin:6px 0 0; padding-left:16px; }
    .trk h4.t-h { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--t-muted); font-weight:700; margin:14px 0 6px; }
    .trk .t-tablewrap { margin-top:20px; }
    .trk .t-filter { font-size:13px; border:1px solid var(--t-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; min-width:240px; margin-bottom:10px; }
    .trk table.t-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .trk table.t-table th, .trk table.t-table td { border-bottom:1px solid var(--t-line); padding:6px 8px; text-align:left; }
    .trk table.t-table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--t-muted); }
    .trk table.t-table tr.row { cursor:pointer; }
    .trk table.t-table tr.row:hover, .trk table.t-table tr.row.sel { background:rgba(37,99,235,.07); }
    .trk .t-num { font-variant-numeric:tabular-nums; }
    .trk .t-note { font-size:12px; color:var(--t-faint); margin-top:8px; }
</style>

<div class="trk">
    @if ($board === null)
        <div class="t-empty">
            No town-rank scans for this site yet. Run <code>launchpad:town-rank {site} --keyword="…" --scan --yes</code> to scan the covered towns.
        </div>
    @else
        <div class="t-chips">
            @foreach ($keywords as $kw)
                <button type="button" class="t-chip {{ $kw['keyword_id'] === $board['keyword_id'] ? 'on' : '' }}" wire:click="$set('keywordId', '{{ $kw['keyword_id'] }}')">{{ $kw['query'] }}</button>
            @endforeach
            <div class="t-modes" role="group" aria-label="Query mode">
                <button type="button" class="{{ ! $isLocal ? 'on' : '' }}" wire:click="setMode('town_query')" title="&quot;keyword Town ST&quot; searched nationally — does the town page win its own search?">Town search</button>
                <button type="button" class="{{ $isLocal ? 'on' : '' }}" wire:click="setMode('local')" title="The bare keyword searched from the town — what a resident sees">Searched from town</button>
            </div>
        </div>

        @php($s = $board['summary'])
        @if ($board['scan'] === null)
            <div class="t-empty">No {{ $isLocal ? 'searched-from-town' : 'town-search' }} scan for “{{ $board['keyword'] }}” yet. Run <code>launchpad:town-rank {site} --keyword="{{ $board['keyword'] }}" --mode={{ $isLocal ? 'local' : 'town' }} --scan --yes</code>.</div>
        @else
            <div class="t-buckets">
                <div class="t-bucket"><div class="n" style="color:#15803d">{{ $s['top3'] }}</div><div class="lab">Top 3</div></div>
                <div class="t-bucket"><div class="n" style="color:#65a30d">{{ $s['page1'] }}</div><div class="lab">Page 1 (4–10)</div></div>
                <div class="t-bucket"><div class="n" style="color:#ca8a04">{{ $s['page2'] }}</div><div class="lab">Page 2</div></div>
                <div class="t-bucket"><div class="n" style="color:#c2410c">{{ $s['beyond'] }}</div><div class="lab">Beyond</div></div>
                <div class="t-bucket"><div class="n" style="color:#9ca3af">{{ $s['not_found'] }}</div><div class="lab">Not found{{ $s['pending'] > 0 ? ' · '.$s['pending'].' pending' : '' }}</div></div>
            </div>
            <div class="t-note">
                “{{ $board['keyword'] }}” · {{ $isLocal ? 'searched from each town' : 'town search: “'.$board['keyword'].' Town ST”' }} · {{ $board['scan']['points'] }} towns · {{ $board['scan']['status'] }} {{ $board['scan']['scanned_at'] ? \Illuminate\Support\Carbon::parse($board['scan']['scanned_at'])->diffForHumans() : '' }}
            </div>
        @endif

        <div class="t-main" style="margin-top:12px">
            <div>
                <div class="t-mapwrap">
                    @php($r = $dotR($board['markers']))
                    <svg class="t-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Town rank map">
                        @foreach ($board['markers'] as $m)
                            <circle class="t-dot {{ $m['id'] === $townId ? 'sel' : '' }} {{ $m['page'] ? '' : 'nopage' }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $m['color'] }}" wire:click="selectTown('{{ $m['id'] }}')">
                                <title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'not found' }}{{ $m['page'] ? '' : ' · no page' }}@if ($m['population'] > 0) · pop {{ number_format($m['population']) }}@endif</title>
                            </circle>
                        @endforeach
                    </svg>
                    <div class="t-legend">
                        <span><i style="background:#15803d"></i>1–3</span><span><i style="background:#65a30d"></i>4–7</span><span><i style="background:#ca8a04"></i>8–10</span><span><i style="background:#c2410c"></i>11–15</span><span><i style="background:#c0392b"></i>16+</span><span><i style="background:#9ca3af"></i>not found</span><span>dashed = no page</span>
                    </div>
                </div>
            </div>

            <div class="t-panel">
                @if ($town === null)
                    <h3>Pick a town</h3>
                    <div class="sub">Click a dot on the map or a row in the table to see who outranks you there and what to do.</div>
                @else
                    <h3>{{ $town['label'] }}</h3>
                    <div class="sub">{{ $town['population'] > 0 ? 'pop '.number_format($town['population']).' · ' : '' }}{{ $town['page_state'] === 'anchored' ? 'page: '.$town['page_url'] : ($town['page_state'] === 'unanchored' ? 'page published, not anchored' : 'no page') }}</div>
                    <div class="t-krow"><span>Town search</span><b>{{ $rankCell($town['town_query']['rank'], $town['town_query']['state']) ?: '—' }}</b></div>
                    <div class="t-krow"><span>Searched from town</span><b>{{ $rankCell($town['local']['rank'], $town['local']['state']) ?: '—' }}</b></div>
                    <div class="t-krow"><span>Map pack</span><b>{{ $town['map_rank'] !== null ? '#'.$town['map_rank'] : ($town['map_scanned'] ? 'not found' : '—') }}</b></div>

                    <h4 class="t-h">What to do</h4>
                    @foreach ($town['actions'] as $a)
                        <div class="t-act" style="border-color:{{ $levelColor($a['level']) }}"><b>{{ $a['title'] }}</b><span>{{ $a['why'] }}</span></div>
                    @endforeach

                    @if ($town['town_query']['competitors'] !== [])
                        <h4 class="t-h">Above you for the town search</h4>
                        <ol class="t-comp">
                            @foreach ($town['town_query']['competitors'] as $c)<li>#{{ $c['position'] }} {{ $c['domain'] }}</li>@endforeach
                        </ol>
                    @endif
                    @if ($town['local']['competitors'] !== [])
                        <h4 class="t-h">Above you searched from town</h4>
                        <ol class="t-comp">
                            @foreach ($town['local']['competitors'] as $c)<li>#{{ $c['position'] }} {{ $c['domain'] }}</li>@endforeach
                        </ol>
                    @endif
                @endif
            </div>
        </div>

        <div class="t-tablewrap">
            <input type="text" class="t-filter" wire:model.live.debounce.300ms="filter" placeholder="Filter towns…" aria-label="Filter towns">
            <table class="t-table">
                <thead><tr><th>Town</th><th>Pop</th><th>Page</th><th>Town search</th><th>From town</th><th>Map pack</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="row {{ $row['coverage_area_id'] === $townId ? 'sel' : '' }}" wire:key="tr-{{ $row['coverage_area_id'] }}" wire:click="selectTown('{{ $row['coverage_area_id'] }}')">
                            <td>{{ $row['label'] }}{{ $row['state'] !== null ? ', '.$row['state'] : '' }}</td>
                            <td class="t-num">{{ $row['population'] > 0 ? number_format((int) $row['population']) : '—' }}</td>
                            <td>{{ $row['page_url'] !== null ? '✓' : '—' }}</td>
                            <td class="t-num">{{ $rankCell($row['town_rank'], (string) $row['town_state']) }}</td>
                            <td class="t-num">{{ $rankCell($row['local_rank'], (string) $row['local_state']) }}</td>
                            <td class="t-num">{{ $row['map_rank'] !== null ? '#'.$row['map_rank'] : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($board['rows']) > count($rows))
                <div class="t-note">Showing {{ count($rows) }} of {{ count($board['rows']) }} towns — filter to narrow.</div>
            @endif
        </div>
    @endif
</div>
</x-filament-panels::page>
