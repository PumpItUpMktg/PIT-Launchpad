<x-filament-panels::page>
@php
    $areas = $this->areas;
    $area = $locationId !== null ? $this->area : null;
    $dotR = function (array $markers): callable {
        $max = max(1, (int) collect($markers)->max('population'));
        return fn (int $pop): float => round(1.6 + sqrt(max(0, $pop) / $max) * 2.6, 2);
    };
    $when = fn (?string $at): string => $at !== null ? \Illuminate\Support\Carbon::parse($at)->diffForHumans() : '';
@endphp

<style>
    .sva { --s-line:#e2e7ee; --s-muted:#5a6675; --s-faint:#8a95a3; --s-surface:#ffffff; --s-surface2:#f6f8fb; }
    .dark .sva { --s-line:#232c37; --s-muted:#9aa7b5; --s-faint:#6b7887; --s-surface:#0b1017; --s-surface2:#0f151c; }
    .sva .s-back { font-size:12px; color:#2563eb; background:none; border:none; cursor:pointer; padding:0; margin-bottom:12px; display:inline-block; }
    .sva .s-empty { padding:44px 16px; text-align:center; color:var(--s-muted); border:1px dashed var(--s-line); border-radius:12px; }
    .sva .s-areas { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:14px; }
    .sva .s-area { border:1px solid var(--s-line); border-radius:14px; background:var(--s-surface); padding:14px; text-align:left; cursor:pointer; color:inherit; width:100%; }
    .sva .s-area:hover { border-color:#2563eb; }
    .sva .s-area h3 { font-size:15px; font-weight:800; margin:0 0 4px; }
    .sva .s-area .sub { font-size:12px; color:var(--s-muted); }
    .sva .s-counties { display:flex; gap:6px; flex-wrap:wrap; margin:8px 0; }
    .sva .s-county { font-size:11px; border:1px solid var(--s-line); border-radius:999px; padding:3px 9px; color:var(--s-muted); }
    .sva .s-head { display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
    .sva .s-head h2 { font-size:18px; font-weight:800; margin:0; }
    .sva .s-head .sub { font-size:12.5px; color:var(--s-muted); }
    .sva .s-cards { display:grid; gap:14px; margin-top:14px; }
    .sva .s-card { border:1px solid var(--s-line); border-radius:14px; background:var(--s-surface); padding:14px; }
    .sva .s-card h3 { font-size:15px; font-weight:800; margin:0 0 10px; }
    .sva .s-cols { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(220px,.8fr); gap:14px; align-items:start; }
    @media (max-width:900px){ .sva .s-cols { grid-template-columns:1fr; } }
    .sva .s-col h4 { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--s-muted); font-weight:700; margin:0 0 6px; }
    .sva .s-mapwrap { border:1px solid var(--s-line); border-radius:12px; background:var(--s-surface2); padding:8px; }
    .sva .s-map { width:100%; height:auto; display:block; aspect-ratio:1/1; }
    .sva .s-county { fill:rgba(37,99,235,.07); stroke:rgba(37,99,235,.55); stroke-width:.5; stroke-linejoin:round; }
    .dark .sva .s-county { fill:rgba(37,99,235,.12); stroke:rgba(96,165,250,.7); }
    .sva .s-dot { stroke:rgba(0,0,0,.25); stroke-width:.4; }
    .sva .s-dot.nopage { stroke-dasharray:1 .6; }
    .sva .s-placeholder { border:1px dashed var(--s-line); border-radius:12px; background:var(--s-surface2); aspect-ratio:1/1; display:flex; align-items:center; justify-content:center; text-align:center; padding:16px; font-size:12.5px; color:var(--s-muted); }
    .sva .s-legend { display:flex; gap:10px; flex-wrap:wrap; font-size:11px; color:var(--s-muted); margin-top:6px; }
    .sva .s-legend b { color:inherit; font-variant-numeric:tabular-nums; }
    .sva .s-when { font-size:11px; color:var(--s-faint); margin-top:4px; }
    .sva .s-runrow { display:flex; align-items:center; gap:10px; margin-top:8px; font-size:11.5px; color:var(--s-faint); flex-wrap:wrap; }
    .sva .s-run { font-size:12px; border:1px solid #2563eb; color:#2563eb; background:transparent; border-radius:8px; padding:5px 10px; cursor:pointer; }
    .sva .s-run:disabled { opacity:.55; cursor:default; }
    .sva .s-metrics { border:1px dashed var(--s-line); border-radius:12px; padding:10px 12px; background:var(--s-surface2); }
    .sva .s-metric { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; padding:5px 0; border-bottom:1px solid var(--s-line); }
    .sva .s-metric:last-child { border-bottom:none; }
    .sva .s-metric b { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .sva .s-metric small { display:block; color:var(--s-faint); font-size:11px; }
</style>

<div class="sva">
    @if ($area === null)
        {{-- Area list: one card per service area (location + the counties it serves). --}}
        @if ($areas === [])
            <div class="s-empty">No service areas yet. A location with a home county (or assigned counties) becomes one.</div>
        @else
            <div class="s-areas">
                @foreach ($areas as $a)
                    <button type="button" class="s-area" wire:key="area-{{ $a['location_id'] }}" wire:click="openArea('{{ $a['location_id'] }}')" title="Open {{ $a['name'] }}">
                        <h3>{{ $a['name'] }}</h3>
                        <div class="sub">{{ trim($a['city'].($a['state'] !== '' ? ', '.$a['state'] : '')) }}</div>
                        <div class="s-counties">
                            @forelse ($a['counties'] as $c)
                                <span class="s-county">{{ $c['label'] }}</span>
                            @empty
                                <span class="s-county">no counties yet</span>
                            @endforelse
                        </div>
                        <div class="sub">{{ number_format($a['towns']) }} towns · {{ $a['keywords'] }} keywords</div>
                    </button>
                @endforeach
            </div>
        @endif
    @else
        <button type="button" class="s-back" wire:click="closeArea">← All service areas</button>
        <div class="s-head">
            <h2>{{ $area['location']['name'] }}</h2>
            <span class="sub">{{ trim($area['location']['city'].($area['location']['state'] !== '' ? ', '.$area['location']['state'] : '')) }} · {{ number_format($area['towns']) }} towns</span>
        </div>
        <div class="s-counties">
            @forelse ($area['counties'] as $c)
                <span class="s-county">{{ $c['label'] }}</span>
            @empty
                <span class="s-county">no counties assigned</span>
            @endforelse
        </div>

        @if ($area['cards'] === [])
            <div class="s-empty">No keywords tracked for Town Rank yet — add them on the Town Rank page.</div>
        @else
            <div class="s-cards">
                @foreach ($area['cards'] as $card)
                    <div class="s-card" wire:key="kw-{{ $card['keyword_id'] }}">
                        <h3>{{ $card['query'] }}</h3>
                        <div class="s-cols">
                            {{-- Column 1: the website's town rank across this area's towns. --}}
                            <div class="s-col">
                                <h4>Website · {{ $card['web'] !== null && $card['web']['mode'] === 'local' ? 'searched from town' : 'town search' }}</h4>
                                @if ($card['web'] === null)
                                    <div class="s-placeholder">No Town Rank scan for this keyword yet — run its ranking report on the Town Rank page.</div>
                                @else
                                    @php($r = $dotR($card['web']['markers']))
                                    <div class="s-mapwrap">
                                        <svg class="s-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Website rank by town">
                                            @foreach ($area['outlines'] as $o)
                                                @foreach ($o['paths'] as $d)
                                                    <path class="s-county" d="{{ $d }}"><title>{{ $o['label'] }}</title></path>
                                                @endforeach
                                            @endforeach
                                            @foreach ($card['web']['markers'] as $m)
                                                <circle class="s-dot {{ $m['page'] ? '' : 'nopage' }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $m['color'] }}"><title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'not found' }}</title></circle>
                                            @endforeach
                                        </svg>
                                    </div>
                                    @php($s = $card['web']['summary'])
                                    <div class="s-legend">
                                        <span>top-3 <b style="color:#15803d">{{ $s['top3'] }}</b></span>
                                        <span>page-1 <b>{{ $s['top3'] + $s['page1'] }}</b></span>
                                        <span>page-2 <b>{{ $s['page2'] }}</b></span>
                                        <span>not found <b style="color:#9ca3af">{{ $s['not_found'] }}</b></span>
                                        @if ($s['pending'] > 0)<span style="color:#2563eb">collecting {{ $s['pending'] }}</span>@endif
                                    </div>
                                    <div class="s-when">{{ $card['web']['status'] }} · {{ $when($card['web']['scanned_at']) }}</div>
                                @endif
                            </div>

                            {{-- Column 2: the GBP's map-pack rank across the same towns. --}}
                            <div class="s-col">
                                <h4>Google Business Profile · map pack</h4>
                                @if ($card['gbp'] === null)
                                    <div class="s-placeholder">No GBP coverage scan for this keyword here yet — it appears once a coverage scan runs for this location and keyword.</div>
                                @else
                                    @php($r = $dotR($card['gbp']['markers']))
                                    <div class="s-mapwrap">
                                        <svg class="s-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img" aria-label="GBP map-pack rank by town">
                                            @foreach ($area['outlines'] as $o)
                                                @foreach ($o['paths'] as $d)
                                                    <path class="s-county" d="{{ $d }}"><title>{{ $o['label'] }}</title></path>
                                                @endforeach
                                            @endforeach
                                            @foreach ($card['gbp']['markers'] as $m)
                                                <circle class="s-dot {{ $m['page'] ? '' : 'nopage' }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $m['color'] }}"><title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'absent' }}</title></circle>
                                            @endforeach
                                        </svg>
                                    </div>
                                    @php($g = $card['gbp']['summary'])
                                    <div class="s-legend">
                                        <span>top-3 <b style="color:#15803d">{{ $g['top3'] }}</b></span>
                                        <span>4–7 <b>{{ $g['top7'] }}</b></span>
                                        <span>8–10 <b>{{ $g['top10'] }}</b></span>
                                        <span>absent <b style="color:#9ca3af">{{ $g['absent'] }}</b></span>
                                        @if ($g['pending'] > 0)<span style="color:#2563eb">collecting {{ $g['pending'] }}</span>@endif
                                    </div>
                                    <div class="s-when">{{ $card['gbp']['status'] }} · {{ $when($card['gbp']['scanned_at']) }}</div>
                                @endif
                                @php($gr = $card['gbp_run'])
                                <div class="s-runrow">
                                    <button type="button" class="s-run" wire:click="runGbp('{{ $card['keyword_id'] }}')" wire:loading.attr="disabled" wire:target="runGbp"
                                            wire:confirm="Post {{ number_format($gr['requests']) }} DataForSEO Maps requests (~${{ number_format($gr['cost'], 2) }}) for “{{ $card['query'] }}” in {{ $area['location']['name'] }}? One search per town from the town’s coordinates; results collect over the next few minutes."
                                            @disabled($gr['pending'])>
                                        {{ $gr['pending'] ? 'Collecting…' : 'Run GBP report' }}
                                    </button>
                                    <span>{{ $gr['pending'] ? 'a GBP report is collecting — the column updates as results land' : number_format($gr['requests']).' requests · ~$'.number_format($gr['cost'], 2) }}</span>
                                </div>
                            </div>

                            {{-- Column 3: the scoring slot — provisional shares now, the score once its formula is chosen. --}}
                            <div class="s-col">
                                <h4>Scoring</h4>
                                <div class="s-metrics">
                                    @foreach ($card['metrics'] as $metric)
                                        <div class="s-metric">
                                            <span>{{ $metric['label'] }}<small>{{ $metric['note'] }}</small></span>
                                            <b>{{ $metric['value'] ?? '—' }}</b>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
</x-filament-panels::page>
