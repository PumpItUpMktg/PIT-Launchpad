<x-filament-panels::page>
@php
    $areas = $this->areas;
    $area = $locationId !== null ? $this->area : null;
    $dotR = function (array $markers): callable {
        $max = max(1, (int) collect($markers)->max('population'));
        return fn (int $pop): float => round(1.6 + sqrt(max(0, $pop) / $max) * 2.6, 2);
    };
    $when = fn (?string $at): string => $at !== null ? \Illuminate\Support\Carbon::parse($at)->diffForHumans() : '';
    $town = $area !== null ? $this->town : null;
    $rankCell = fn ($rank, string $state): string => match ($state) {
        'unscanned' => '',
        'pending' => '…',
        'not_found' => 'not found',
        default => '#'.(int) $rank,
    };
    $levelColor = fn (string $level): string => match ($level) { 'do' => '#c0392b', 'watch' => '#ca8a04', default => '#15803d' };
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
    .sva .s-county { fill:none; stroke:rgba(37,99,235,.7); stroke-width:.6; stroke-linejoin:round; }
    .dark .sva .s-county { stroke:rgba(96,165,250,.8); }
    .sva .s-dot { stroke:rgba(0,0,0,.25); stroke-width:.4; cursor:pointer; }
    .sva .s-dot.nopage { stroke-dasharray:1 .6; }
    .sva .s-dot.sel { stroke:#2563eb; stroke-width:1.2; }
    .sva .s-shape { stroke:rgba(255,255,255,.55); stroke-width:.35; stroke-linejoin:round; cursor:pointer; }
    .dark .sva .s-shape { stroke:rgba(0,0,0,.45); }
    .sva .s-shape:hover { stroke:#2563eb; stroke-width:.9; }
    .sva .s-shape.sel { stroke:#2563eb; stroke-width:1.2; }
    /* The map-pack position, drawn into the town it belongs to: white numerals carrying a dark outline via
       paint-order, so they stay readable on a green, amber, red or grey fill in either theme. */
    .sva .s-rank { font-size:2.6px; font-weight:700; fill:#fff; stroke:rgba(0,0,0,.65); stroke-width:.55px;
                   paint-order:stroke fill; text-anchor:middle; dominant-baseline:central;
                   font-family:'Spline Sans Mono',ui-monospace,monospace; }
    .sva .s-town { border:1px solid var(--s-line); border-radius:12px; padding:12px 14px; background:var(--s-surface2); margin-top:12px; }
    .sva .s-town h5 { font-size:14px; font-weight:800; margin:0 0 2px; display:flex; justify-content:space-between; gap:10px; }
    .sva .s-town .sub { font-size:12px; color:var(--s-muted); margin-bottom:8px; }
    .sva .s-town .s-close { font-size:12px; color:#2563eb; background:none; border:none; cursor:pointer; padding:0; font-weight:600; }
    .sva .s-krow { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; padding:5px 0; border-bottom:1px solid var(--s-line); }
    .sva .s-krow:last-child { border-bottom:none; }
    .sva .s-krow b { font-variant-numeric:tabular-nums; white-space:nowrap; }
    .sva .s-act { border-left:3px solid; padding:6px 10px; margin-top:8px; font-size:12.5px; background:var(--s-surface); border-radius:0 8px 8px 0; }
    .sva .s-act b { display:block; }
    .sva .s-act span { color:var(--s-muted); }
    .sva .s-comp { font-size:12px; color:var(--s-muted); margin:6px 0 0; padding-left:16px; }
    .sva h6.s-h { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--s-muted); font-weight:700; margin:12px 0 4px; }
    .sva .s-towncols { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.4fr); gap:14px; }
    @media (max-width:900px){ .sva .s-towncols { grid-template-columns:1fr; } }
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
                                            @foreach ($card['web']['markers'] as $m)
                                                @php($sel = $keywordId === $card['keyword_id'] && $townId === $m['id'])
                                                @if (isset($area['town_paths'][$m['id']]))
                                                    {{-- The town's own boundary, coloured by its rank. --}}
                                                    @foreach ($area['town_paths'][$m['id']] as $d)
                                                        <path class="s-shape {{ $sel ? 'sel' : '' }}" d="{{ $d }}" fill="{{ $m['color'] }}" wire:click="selectTown('{{ $card['keyword_id'] }}', '{{ $m['id'] }}')"><title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'not found' }}</title></path>
                                                    @endforeach
                                                @else
                                                    <circle class="s-dot {{ $m['page'] ? '' : 'nopage' }} {{ $sel ? 'sel' : '' }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $m['color'] }}" wire:click="selectTown('{{ $card['keyword_id'] }}', '{{ $m['id'] }}')"><title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'not found' }}</title></circle>
                                                @endif
                                            @endforeach
                                            @foreach ($area['outlines'] as $o)
                                                @foreach ($o['paths'] as $d)
                                                    <path class="s-county" d="{{ $d }}" pointer-events="none"><title>{{ $o['label'] }}</title></path>
                                                @endforeach
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
                                    <div class="s-when">
                                        {{ $card['web']['status'] }} · {{ $when($card['web']['scanned_at']) }}
                                        @if (($card['web']['progress'] ?? null) !== null && $card['web']['progress']['eta'] !== null)
                                            · {{ number_format($card['web']['progress']['remaining']) }} town(s) left, at least {{ $card['web']['progress']['eta'] }}
                                        @elseif (($card['web']['uncollected'] ?? 0) > 0)
                                            · <span style="color:#b45309">{{ number_format($card['web']['uncollected']) }} town(s) never collected</span>
                                        @endif
                                    </div>
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
                                            @foreach ($card['gbp']['markers'] as $m)
                                                @php($sel = $keywordId === $card['keyword_id'] && $townId === $m['id'])
                                                @if (isset($area['town_paths'][$m['id']]))
                                                    @foreach ($area['town_paths'][$m['id']] as $d)
                                                        <path class="s-shape {{ $sel ? 'sel' : '' }}" d="{{ $d }}" fill="{{ $m['color'] }}" wire:click="selectTown('{{ $card['keyword_id'] }}', '{{ $m['id'] }}')"><title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'absent' }}</title></path>
                                                    @endforeach
                                                @else
                                                    <circle class="s-dot {{ $m['page'] ? '' : 'nopage' }} {{ $sel ? 'sel' : '' }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="{{ $r($m['population']) }}" fill="{{ $m['color'] }}" wire:click="selectTown('{{ $card['keyword_id'] }}', '{{ $m['id'] }}')"><title>{{ $m['label'] }} — {{ $m['rank'] !== null ? '#'.$m['rank'] : 'absent' }}</title></circle>
                                                @endif
                                            @endforeach
                                            @foreach ($area['outlines'] as $o)
                                                @foreach ($o['paths'] as $d)
                                                    <path class="s-county" d="{{ $d }}" pointer-events="none"><title>{{ $o['label'] }}</title></path>
                                                @endforeach
                                            @endforeach
                                            {{-- The map-pack position itself, over each town it was found in. Drawn last so the
                                                 county line never crosses a numeral; a town we're absent from carries no number. --}}
                                            @foreach ($card['gbp']['markers'] as $m)
                                                @if ($m['rank'] !== null)
                                                    <text class="s-rank" x="{{ $m['x'] }}" y="{{ $m['y'] }}" pointer-events="none">{{ $m['rank'] }}</text>
                                                @endif
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
                                    <div class="s-when">
                                        {{ $card['gbp']['status'] }} · {{ $when($card['gbp']['scanned_at']) }}
                                        @if (($card['gbp']['progress'] ?? null) !== null && $card['gbp']['progress']['eta'] !== null)
                                            · {{ number_format($card['gbp']['progress']['remaining']) }} town(s) left, at least {{ $card['gbp']['progress']['eta'] }}
                                        @elseif (($card['gbp']['uncollected'] ?? 0) > 0)
                                            · <span style="color:#b45309">{{ number_format($card['gbp']['uncollected']) }} town(s) never collected</span>
                                        @endif
                                    </div>
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

                        {{-- The selected town's detail (a dot on either map), the Town Rank board's panel. --}}
                        @if ($town !== null && $keywordId === $card['keyword_id'])
                            <div class="s-town" wire:key="town-{{ $card['keyword_id'] }}-{{ $town['id'] }}">
                                <h5><span>{{ $town['label'] }}</span><button type="button" class="s-close" wire:click="clearTown">close</button></h5>
                                <div class="sub">{{ $town['population'] > 0 ? 'pop '.number_format($town['population']).' · ' : '' }}{{ $town['page_state'] === 'anchored' ? 'page: '.$town['page_url'] : ($town['page_state'] === 'slug' ? 'page found by slug (GEOID differs): '.$town['page_url'] : 'no page') }}</div>
                                <div class="s-towncols">
                                    <div>
                                        <div class="s-krow"><span>Town search</span><b>{{ $rankCell($town['town_query']['rank'], $town['town_query']['state']) ?: '—' }}{{ $town['town_query']['prev_rank'] !== null && $town['town_query']['change'] !== null ? ' (was #'.$town['town_query']['prev_rank'].')' : '' }}</b></div>
                                        <div class="s-krow"><span>Searched from town</span><b>{{ $rankCell($town['local']['rank'], $town['local']['state']) ?: '—' }}{{ $town['local']['prev_rank'] !== null && $town['local']['change'] !== null ? ' (was #'.$town['local']['prev_rank'].')' : '' }}</b></div>
                                        <div class="s-krow"><span>GBP map pack</span><b>{{ $town['map_rank'] !== null ? '#'.$town['map_rank'] : ($town['map_scanned'] ? 'absent' : '—') }}</b></div>
                                        @if ($town['town_query']['competitors'] !== [])
                                            <h6 class="s-h">Above you for the town search</h6>
                                            <ol class="s-comp">
                                                @foreach ($town['town_query']['competitors'] as $c)<li>#{{ $c['position'] }} {{ $c['domain'] }}</li>@endforeach
                                            </ol>
                                        @endif
                                        @if ($town['local']['competitors'] !== [])
                                            <h6 class="s-h">Above you searched from town</h6>
                                            <ol class="s-comp">
                                                @foreach ($town['local']['competitors'] as $c)<li>#{{ $c['position'] }} {{ $c['domain'] }}</li>@endforeach
                                            </ol>
                                        @endif
                                    </div>
                                    <div>
                                        <h6 class="s-h" style="margin-top:0">What to do</h6>
                                        @foreach ($town['actions'] as $a)
                                            <div class="s-act" style="border-color:{{ $levelColor($a['level']) }}"><b>{{ $a['title'] }}</b><span>{{ $a['why'] }}</span></div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
</x-filament-panels::page>
