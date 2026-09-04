<x-filament-panels::page>
@php($d = $this->dashboard)

<style>
    .ld { --ld-line:#e2e7ee; --ld-muted:#5a6675; --ld-faint:#8a95a3; --ld-surface:#ffffff; --ld-surface2:#f6f8fb; }
    .dark .ld { --ld-line:#232c37; --ld-muted:#9aa7b5; --ld-faint:#6b7887; --ld-surface:#0b1017; --ld-surface2:#0f151c; }
    .ld .ld-controls { display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
    .ld .ld-select { font-size:13px; border:1px solid var(--ld-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; }
    .ld .ld-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:16px; }
    .ld .ld-card { border:1px solid var(--ld-line); border-radius:12px; padding:16px; background:var(--ld-surface); }
    .ld .ld-card h3 { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--ld-muted); font-weight:700; margin:0 0 12px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .ld .ld-stat-row { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:12px; }
    .ld .ld-stat { display:flex; flex-direction:column; }
    .ld .ld-stat .n { font-size:24px; font-weight:800; font-variant-numeric:tabular-nums; line-height:1.1; }
    .ld .ld-stat .l { font-size:11px; color:var(--ld-muted); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
    .ld table.ld-t { width:100%; border-collapse:collapse; font-size:12.5px; }
    .ld .ld-t th { text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ld-muted); font-weight:700; padding:0 8px 6px 0; border-bottom:1px solid var(--ld-line); }
    .ld .ld-t td { padding:6px 8px 6px 0; border-bottom:1px solid var(--ld-line); vertical-align:top; }
    .ld .ld-t td.num { text-align:right; font-variant-numeric:tabular-nums; }
    .ld .up { color:#15803d; font-weight:700; }
    .ld .down { color:#c0392b; font-weight:700; }
    .ld .flat { color:var(--ld-faint); }
    .ld .ld-pill { font-size:11px; font-weight:700; border-radius:999px; padding:2px 9px; }
    .ld .ld-pill.ok { background:rgba(22,163,74,.16); color:#15803d; }
    .ld .ld-pill.no { background:rgba(148,163,184,.16); color:var(--ld-faint); }
    .ld .ld-link { font-size:12px; font-weight:600; color:#2563eb; text-decoration:none; }
    .ld .ld-muted { color:var(--ld-muted); font-size:12.5px; }
    .ld .ld-empty { color:var(--ld-faint); font-size:12.5px; font-style:italic; }
    .ld .ld-bignote { padding:40px 16px; text-align:center; color:var(--ld-muted); border:1px dashed var(--ld-line); border-radius:12px; }
    .ld .ld-wide { grid-column:1 / -1; }
</style>

<div class="ld">
    <div class="ld-controls">
        <select class="ld-select" wire:model.live="locationId" aria-label="Location">
            @forelse ($this->locations as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @empty
                <option value="">No GBP-backed locations</option>
            @endforelse
        </select>
    </div>

    @if ($d === null)
        <div class="ld-bignote">Pick a GBP-backed location to see its cluster dashboard. Non-visitable bases (home, storage) have no dashboard.</div>
    @else
        <div class="ld-grid">
            {{-- 1 · Cluster performance (GSC) --}}
            <div class="ld-card">
                <h3>Cluster performance <span class="ld-muted">last {{ $d['performance']['window_days'] }}d</span></h3>
                <div class="ld-stat-row">
                    <div class="ld-stat"><span class="n">{{ number_format($d['performance']['impressions']) }}</span><span class="l">Impressions</span></div>
                    <div class="ld-stat"><span class="n">{{ number_format($d['performance']['clicks']) }}</span><span class="l">Clicks</span></div>
                </div>
                @if (empty($d['performance']['pages']))
                    <p class="ld-empty">No pages in this cluster yet.</p>
                @else
                    <table class="ld-t">
                        <thead><tr><th>Page</th><th style="text-align:right">Impr</th><th style="text-align:right">Clicks</th></tr></thead>
                        <tbody>
                            @foreach (array_slice($d['performance']['pages'], 0, 8) as $p)
                                <tr><td>{{ $p['title'] }}</td><td class="num">{{ number_format($p['impressions']) }}</td><td class="num">{{ number_format($p['clicks']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- 2 · Cluster inventory --}}
            <div class="ld-card">
                <h3>Cluster inventory</h3>
                <div class="ld-stat-row">
                    <div class="ld-stat"><span class="n">{{ $d['inventory']['pages_live'] }}<span class="ld-muted" style="font-size:14px;font-weight:600;">/{{ $d['inventory']['pages_total'] }}</span></span><span class="l">Pages live</span></div>
                    <div class="ld-stat"><span class="n">{{ $d['inventory']['towns_selected'] }}<span class="ld-muted" style="font-size:14px;font-weight:600;">/{{ $d['inventory']['towns_covered'] }}</span></span><span class="l">Towns selected</span></div>
                </div>
                <p class="ld-muted">
                    Population reached:
                    <b>{{ number_format($d['inventory']['population_published']) }}</b>
                    of {{ number_format($d['inventory']['population_total']) }}
                    @if ($d['inventory']['population_total'] > 0)
                        ({{ round($d['inventory']['population_published'] / $d['inventory']['population_total'] * 100) }}%)
                    @endif
                    · Hub page {!! $d['inventory']['hub_live'] ? '<span class="ld-pill ok">live</span>' : '<span class="ld-pill no">not live</span>' !!}
                </p>
            </div>

            {{-- 3 · Cluster indexing --}}
            <div class="ld-card">
                <h3>Cluster indexing</h3>
                <div class="ld-stat-row">
                    <div class="ld-stat"><span class="n">{{ $d['indexing']['indexed'] }}<span class="ld-muted" style="font-size:14px;font-weight:600;">/{{ $d['indexing']['known'] }}</span></span><span class="l">Indexed / known</span></div>
                    <div class="ld-stat"><span class="n">{{ $d['indexing']['pending'] }}</span><span class="l">Awaiting</span></div>
                </div>
                <p class="ld-muted">Indexed = earned Search impressions or a URL-Inspection PASS. A correct exclusion (redirect/canonical) isn't counted as pending.</p>
            </div>

            {{-- 4 · Keyword movement --}}
            <div class="ld-card ld-wide">
                <h3>Keyword movement <span class="ld-muted">location-scoped organic</span></h3>
                @if (empty($d['keywords']))
                    <p class="ld-empty">No target keywords on this cluster's pages yet.</p>
                @else
                    <table class="ld-t">
                        <thead><tr><th>Keyword</th><th>Page</th><th style="text-align:right">Rank</th><th style="text-align:right">Δ</th></tr></thead>
                        <tbody>
                            @foreach ($d['keywords'] as $k)
                                <tr>
                                    <td>{{ $k['keyword'] }}</td>
                                    <td class="ld-muted">{{ $k['page'] }}</td>
                                    <td class="num">{{ $k['rank'] ?? '—' }}</td>
                                    <td class="num">
                                        @if ($k['delta'] === null)<span class="flat">—</span>
                                        @elseif ($k['delta'] > 0)<span class="up">▲ {{ $k['delta'] }}</span>
                                        @elseif ($k['delta'] < 0)<span class="down">▼ {{ abs($k['delta']) }}</span>
                                        @else <span class="flat">±0</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- 5 · Geo grids --}}
            <div class="ld-card">
                <h3>Geo grids @if ($d['geo_grid']['available'] && $d['geo_grid']['keyword_count'] > 0)<a class="ld-link" href="{{ $this->geoGridUrl() }}">Open board →</a>@endif</h3>
                @if (! $d['geo_grid']['available'])
                    <p class="ld-empty">Not a GBP-backed location — no grid.</p>
                @elseif ($d['geo_grid']['keyword_count'] === 0)
                    <p class="ld-empty">No geo-grid scans yet. Flag keywords <code>is_grid_keyword</code> and run <code>launchpad:geo-grid-scan</code>.</p>
                @else
                    <div class="ld-stat-row">
                        <div class="ld-stat"><span class="n">{{ $d['geo_grid']['keyword_count'] }}</span><span class="l">Keywords scanned</span></div>
                        <div class="ld-stat"><span class="n">{{ $d['geo_grid']['mean_solv'] !== null ? number_format($d['geo_grid']['mean_solv'], 0).'%' : '—' }}</span><span class="l">Mean SoLV</span></div>
                    </div>
                    <p class="ld-muted">ATRP {{ $d['geo_grid']['best_atrp'] !== null ? number_format($d['geo_grid']['best_atrp'], 1) : '—' }}–{{ $d['geo_grid']['worst_atrp'] !== null ? number_format($d['geo_grid']['worst_atrp'], 1) : '—' }} (best–worst) · <b>uncalibrated</b>, trend only.</p>
                @endif
            </div>

            {{-- 6 · Reviews --}}
            <div class="ld-card">
                <h3>Reviews</h3>
                @if (! $d['reviews']['available'])
                    <p class="ld-empty">Review provider not connected yet — nothing to show.</p>
                @else
                    <div class="ld-stat-row">
                        <div class="ld-stat"><span class="n">{{ $d['reviews']['average'] }}★</span><span class="l">Avg rating</span></div>
                        <div class="ld-stat"><span class="n">{{ $d['reviews']['count'] }}</span><span class="l">Reviews</span></div>
                    </div>
                    @foreach ($d['reviews']['items'] as $r)
                        <p class="ld-muted" style="margin-bottom:6px;"><b>{{ $r['author'] }}</b> ({{ $r['rating'] }}★, {{ $r['town'] }}) — {{ \Illuminate\Support\Str::limit($r['text'], 90) }}</p>
                    @endforeach
                @endif
            </div>

            {{-- 7 · Jobs --}}
            <div class="ld-card">
                <h3>Recent jobs <span class="ld-muted">within radius</span></h3>
                @if (empty($d['jobs']['items']))
                    <p class="ld-empty">No published jobs near this location.</p>
                @else
                    <table class="ld-t">
                        <thead><tr><th>Job</th><th>Town</th><th>Date</th></tr></thead>
                        <tbody>
                            @foreach ($d['jobs']['items'] as $j)
                                <tr><td>{{ \Illuminate\Support\Str::limit($j['title'], 40) }}</td><td class="ld-muted">{{ $j['town'] }}</td><td class="ld-muted">{{ $j['date'] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
