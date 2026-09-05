<x-lp.shell
    variant="table"
    eyebrow="Results"
    title="Rankings"
    lede="Where the tenant is moving in search — organic keywords that climbed or newly ranked, local-pack standings per market, and cannibalization to fix. Observed movement from tracked snapshots, not attribution.">

    @php($board = $this->board)
    @php($s = $board['summary'])

    <style>
        .rk-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .rk-stat { background:var(--card); border:1px solid var(--line); border-radius:11px; padding:12px 16px; min-width:120px; }
        .rk-stat .n { font-family:'Spline Sans Mono',monospace; font-size:22px; font-weight:600; color:var(--teal-deep); }
        .rk-stat .n.good { color:#2E7D6B; } .rk-stat .n.bad { color:#B5341A; }
        .rk-stat .l { font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
        .rk-grid { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(0,1fr); gap:16px; align-items:start; }
        @media (max-width:900px){ .rk-grid { grid-template-columns:1fr; } }
        .rk-card { background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .rk-card h3 { font-family:'Archivo',sans-serif; font-size:13px; font-weight:700; margin:0; padding:12px 16px; border-bottom:1px solid var(--line); background:var(--paper); }
        .rk-table { width:100%; border-collapse:collapse; font-size:13px; }
        .rk-table th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; padding:9px 16px; border-bottom:1px solid var(--line); }
        .rk-table td { padding:9px 16px; border-bottom:1px solid var(--line); vertical-align:middle; }
        .rk-table tr:last-child td { border-bottom:0; }
        .rk-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
        .rk-q { font-weight:600; color:var(--ink); }
        .rk-move { font-family:'Spline Sans Mono',monospace; font-size:12px; }
        .rk-up { color:#2E7D6B; font-weight:700; }
        .rk-new { color:#2B5C7A; font-weight:700; }
        .rk-rank { color:var(--ink-soft); font-variant-numeric:tabular-nums; }
        .rk-note { font-size:12px; color:var(--ink-soft); padding:11px 16px; }
        .rk-empty { padding:22px 16px; color:var(--ink-soft); font-size:13px; }
    </style>

    @if ($this->siteId === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to see its rankings.
        </x-lp.empty>
    @elseif ($s['tracked'] === 0)
        <x-lp.empty title="No tracked positions yet" action="Open Keywords" :href="\App\Filament\Resources\KeywordResource::getUrl('index')">
            Rankings appear once the position tracker has captured snapshots for this tenant's keywords. Nothing has been sampled yet.
        </x-lp.empty>
    @else
        <div class="rk-stats">
            <div class="rk-stat"><div class="n">{{ number_format($s['tracked']) }}</div><div class="l">Keywords tracked</div></div>
            <div class="rk-stat"><div class="n good">{{ number_format($s['improved']) }}</div><div class="l">Improved</div></div>
            <div class="rk-stat"><div class="n good">{{ number_format($s['newly_ranked']) }}</div><div class="l">Newly ranked</div></div>
            <div class="rk-stat"><div class="n">{{ number_format($s['markets_tracked']) }}</div><div class="l">Markets w/ local</div></div>
            <div class="rk-stat"><div class="n {{ $s['cannibalized'] ? 'bad' : '' }}">{{ number_format($s['cannibalized']) }}</div><div class="l">Cannibalized</div></div>
        </div>

        <div class="rk-grid">
            <div class="rk-card">
                <h3>Movers <span style="color:var(--ink-soft);font-weight:600">organic, over the tracked window</span></h3>
                @if (empty($board['movers']))
                    <div class="rk-empty">No upward movement yet in the tracked window.</div>
                @else
                    <table class="rk-table">
                        <thead><tr><th>Keyword</th><th style="text-align:right">Was</th><th style="text-align:right">Now</th><th style="text-align:right">Move</th></tr></thead>
                        <tbody>
                            @foreach (array_slice($board['movers'], 0, 20) as $m)
                                <tr>
                                    <td class="rk-q">{{ $m['query'] }}</td>
                                    <td class="num rk-rank">{{ $m['from'] ?? '—' }}</td>
                                    <td class="num rk-rank">{{ $m['to'] ?? '—' }}</td>
                                    <td class="num rk-move">
                                        @if ($m['is_new'])<span class="rk-new">NEW → {{ $m['to'] }}</span>
                                        @else<span class="rk-up">▲ {{ $m['delta'] }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div>
                <div class="rk-card" style="margin-bottom:16px">
                    <h3>Local-pack standings <span style="color:var(--ink-soft);font-weight:600">latest per market</span></h3>
                    @if (empty($board['local']))
                        <div class="rk-empty">No local-pack snapshots yet.</div>
                    @else
                        <table class="rk-table">
                            <thead><tr><th>Market</th><th style="text-align:right">Kw</th><th style="text-align:right">Avg</th><th style="text-align:right">Top 3</th></tr></thead>
                            <tbody>
                                @foreach ($board['local'] as $l)
                                    <tr>
                                        <td class="rk-q">{{ $l['market_name'] ?: '—' }}</td>
                                        <td class="num rk-rank">{{ $l['keywords'] }}</td>
                                        <td class="num rk-rank">{{ $l['avg_rank'] !== null ? number_format($l['avg_rank'], 1) : '—' }}</td>
                                        <td class="num rk-rank">{{ $l['in_top3'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                <div class="rk-card">
                    <h3>Cannibalization <span style="color:var(--ink-soft);font-weight:600">multiple owned URLs on one keyword</span></h3>
                    @if (empty($board['cannibalized']))
                        <div class="rk-note">None — no keyword has more than one of your URLs competing in its latest capture.</div>
                    @else
                        <table class="rk-table">
                            <thead><tr><th>Keyword</th><th style="text-align:right">Owned URLs</th></tr></thead>
                            <tbody>
                                @foreach ($board['cannibalized'] as $c)
                                    <tr><td class="rk-q">{{ $c['query'] }}</td><td class="num rk-rank">{{ $c['urls'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    @endif
</x-lp.shell>
