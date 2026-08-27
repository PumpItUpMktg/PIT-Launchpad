<x-filament-panels::page>
@php
    $cov = $this->coverage;
    // Closures (not named functions) so re-renders don't redeclare.
    $scoreColor = fn ($s): string => $s === null ? '#9ca3af' : ($s >= 70 ? '#15803d' : ($s >= 40 ? '#ca8a04' : '#c0392b'));
    // Dot radius scaled by population (sqrt), bounded — bigger town, bigger dot.
    $dotR = function (array $markers): callable {
        $max = max(1, (int) collect($markers)->max('population'));
        return fn (int $pop): float => round(1.6 + sqrt(max(0, $pop) / $max) * 2.6, 2);
    };
@endphp

<style>
    .lcov { --l-line:#e2e7ee; --l-muted:#5a6675; --l-faint:#8a95a3; --l-surface:#ffffff; --l-surface2:#f6f8fb; }
    .dark .lcov { --l-line:#232c37; --l-muted:#9aa7b5; --l-faint:#6b7887; --l-surface:#0b1017; --l-surface2:#0f151c; }
    .lcov .l-controls { display:flex; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .lcov .l-select { font-size:13px; border:1px solid var(--l-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; }
    .lcov .l-services { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
    .lcov .l-svc { font-size:12px; border:1px solid var(--l-line); border-radius:999px; padding:6px 12px; background:transparent; color:var(--l-muted); cursor:pointer; display:flex; align-items:center; gap:7px; }
    .lcov .l-svc.on { border-color:#2563eb; color:inherit; background:rgba(37,99,235,.08); font-weight:600; }
    .lcov .l-svc b { font-variant-numeric:tabular-nums; }
    .lcov .l-warn { font-size:12px; color:#b45309; background:rgba(217,119,6,.10); border:1px solid rgba(217,119,6,.30); border-radius:8px; padding:8px 12px; margin-bottom:16px; }
    .lcov .l-empty { padding:44px 16px; text-align:center; color:var(--l-muted); border:1px dashed var(--l-line); border-radius:12px; }

    .lcov .l-main { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(220px,1fr); gap:20px; align-items:start; }
    @media (max-width:820px){ .lcov .l-main { grid-template-columns:1fr; } }
    .lcov .l-mapwrap { border:1px solid var(--l-line); border-radius:14px; background:var(--l-surface2); padding:10px; }
    .lcov .l-map { width:100%; height:auto; display:block; aspect-ratio:1/1; }
    .lcov .l-dot { stroke:rgba(0,0,0,.25); stroke-width:.4; }
    .lcov .l-side { display:flex; flex-direction:column; gap:14px; }
    .lcov .l-score { border:1px solid var(--l-line); border-radius:14px; padding:16px; background:var(--l-surface); text-align:center; }
    .lcov .l-score .n { font-size:52px; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
    .lcov .l-score .lab { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--l-muted); font-weight:700; margin-top:4px; }
    .lcov .l-bar { height:8px; border-radius:999px; background:var(--l-surface2); overflow:hidden; margin-top:12px; }
    .lcov .l-bar i { display:block; height:100%; }
    .lcov .l-delta { font-size:12px; font-weight:700; margin-top:8px; }
    .lcov .l-delta.up { color:#15803d; } .lcov .l-delta.down { color:#c0392b; } .lcov .l-delta.flat { color:var(--l-faint); }
    .lcov .l-metrics { border:1px solid var(--l-line); border-radius:14px; padding:14px; background:var(--l-surface); }
    .lcov .l-metrics h4 { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--l-muted); font-weight:700; margin:0 0 10px; }
    .lcov .l-mrow { display:flex; justify-content:space-between; font-size:12.5px; padding:4px 0; border-bottom:1px solid var(--l-line); }
    .lcov .l-mrow:last-child { border-bottom:none; }
    .lcov .l-mrow b { font-variant-numeric:tabular-nums; }
    .lcov .l-meta { font-size:12px; color:var(--l-muted); margin-top:6px; }

    .lcov h3.l-h { font-size:14px; font-weight:700; margin:26px 0 12px; }
    .lcov .l-strip { display:flex; gap:12px; overflow-x:auto; padding-bottom:6px; }
    .lcov .l-frame { flex:0 0 auto; width:120px; border:1px solid var(--l-line); border-radius:10px; background:var(--l-surface); padding:8px; text-align:center; }
    .lcov .l-frame .fmap { width:100%; height:auto; display:block; aspect-ratio:1/1; background:var(--l-surface2); border-radius:6px; }
    .lcov .l-frame .fdate { font-size:10.5px; color:var(--l-muted); margin-top:6px; }
    .lcov .l-frame .fscore { font-size:15px; font-weight:800; font-variant-numeric:tabular-nums; }
</style>

<div class="lcov">
    <div class="l-controls">
        @if (count($this->sites) > 1)
            <select class="l-select" wire:model.live="siteId" aria-label="Tenant">
                @foreach ($this->sites as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        @endif
        <select class="l-select" wire:model.live="locationId" aria-label="Location">
            @forelse ($this->locations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>
            @empty<option value="">No GBP-backed locations</option>@endforelse
        </select>
    </div>

    <div class="l-warn">Internal test build — town scans are <b>uncalibrated</b>; the Local Visibility Score is a population-weighted rank measure, directional only.</div>

    @if ($cov === null || empty($cov['services']))
        <div class="l-empty">
            No coverage scans for this location yet. Run <code>launchpad:geo-grid-coverage {{ $cov['location']['name'] ?? '{site}' }}</code> to scan its served towns.
        </div>
    @else
        {{-- Service (keyword) chips — click to switch, each shows its latest score. --}}
        <div class="l-services">
            @foreach ($cov['services'] as $svc)
                <button type="button" class="l-svc {{ $svc['keyword_id'] === $cov['keyword_id'] ? 'on' : '' }}" wire:click="$set('keywordId', '{{ $svc['keyword_id'] }}')">
                    {{ $svc['query'] }}
                    <b style="color:{{ $scoreColor($svc['score']) }}">{{ $svc['score'] !== null ? number_format($svc['score'], 0) : '—' }}</b>
                </button>
            @endforeach
        </div>

        @php($c = $cov['current'])
        @if ($c === null)
            <div class="l-empty">No scan for this service yet.</div>
        @else
            <div class="l-main">
                {{-- Current scan — large, centered town scatter. --}}
                <div class="l-mapwrap">
                    @php($r = $dotR($c['markers']))
                    <svg class="l-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Town coverage map">
                        @foreach ($c['markers'] as $m)
                            <circle class="l-dot" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $m['color'] }}">
                                <title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'not found' }}@if ($m['population'] > 0) · pop {{ number_format($m['population']) }}@endif</title>
                            </circle>
                        @endforeach
                    </svg>
                </div>

                <div class="l-side">
                    <div class="l-score">
                        <div class="n" style="color:{{ $scoreColor($c['score']) }}">{{ $c['score'] !== null ? number_format($c['score'], 0) : '—' }}</div>
                        <div class="lab">Local Visibility Score</div>
                        <div class="l-bar"><i style="width:{{ (float) ($c['score'] ?? 0) }}%; background:{{ $scoreColor($c['score']) }};"></i></div>
                        @if ($c['delta'] !== null)
                            <div class="l-delta {{ $c['delta'] > 0 ? 'up' : ($c['delta'] < 0 ? 'down' : 'flat') }}">
                                {{ $c['delta'] > 0 ? '▲' : ($c['delta'] < 0 ? '▼' : '±') }} {{ number_format(abs($c['delta']), 1) }} vs previous
                            </div>
                        @endif
                        <div class="l-meta">{{ $c['found_count'] }}/{{ $c['town_count'] }} towns found · {{ $c['scanned_at'] ? \Illuminate\Support\Carbon::parse($c['scanned_at'])->diffForHumans() : '—' }}</div>
                    </div>

                    <div class="l-metrics">
                        <h4>Where you rank</h4>
                        <div class="l-mrow"><span>Towns you appear in</span><b>{{ $c['metrics']['found_rate'] !== null ? number_format($c['metrics']['found_rate'], 0).'%' : '—' }}</b></div>
                        <div class="l-mrow"><span>Population reached</span><b>{{ $c['metrics']['pop_found_rate'] !== null ? number_format($c['metrics']['pop_found_rate'], 0).'%' : '—' }}</b></div>
                        <div class="l-mrow"><span>Population in your top-3</span><b>{{ $c['metrics']['pop_solv'] !== null ? number_format($c['metrics']['pop_solv'], 0).'%' : '—' }}</b></div>
                        <div class="l-mrow"><span>Avg rank (found)</span><b>{{ $c['metrics']['arp'] !== null ? number_format($c['metrics']['arp'], 1) : '—' }}</b></div>
                        <div class="l-mrow"><span>Avg rank (all towns)</span><b>{{ $c['metrics']['atrp'] !== null ? number_format($c['metrics']['atrp'], 1) : '—' }}</b></div>
                    </div>
                </div>
            </div>

            {{-- History filmstrip — prior scans as small units, newest first. --}}
            @if (! empty($cov['history']))
                <h3 class="l-h">Progress — earlier scans</h3>
                <div class="l-strip">
                    @foreach ($cov['history'] as $h)
                        @php($hr = $dotR($h['markers']))
                        <div class="l-frame">
                            <svg class="fmap" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
                                @foreach ($h['markers'] as $m)
                                    <circle cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $hr($m['population']) }}" fill="{{ $m['color'] }}" />
                                @endforeach
                            </svg>
                            <div class="fscore" style="color:{{ $scoreColor($h['score']) }}">{{ $h['score'] !== null ? number_format($h['score'], 0) : '—' }}</div>
                            <div class="fdate">{{ $h['scanned_at'] ? \Illuminate\Support\Carbon::parse($h['scanned_at'])->format('M j') : '—' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    @endif
</div>
</x-filament-panels::page>
