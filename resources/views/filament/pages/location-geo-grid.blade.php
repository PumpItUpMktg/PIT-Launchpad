<x-filament-panels::page>
@php($board = $this->board)

<style>
    .lgg [x-cloak] { display:none !important; }
    .lgg { --lgg-line:#e2e7ee; --lgg-muted:#5a6675; --lgg-faint:#8a95a3; --lgg-surface:#ffffff; --lgg-surface2:#f6f8fb; }
    .dark .lgg { --lgg-line:#232c37; --lgg-muted:#9aa7b5; --lgg-faint:#6b7887; --lgg-surface:#0b1017; --lgg-surface2:#0f151c; }
    .lgg .lgg-controls { display:flex; align-items:center; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .lgg .lgg-select { font-size:13px; border:1px solid var(--lgg-line); border-radius:8px; padding:7px 12px; background:transparent; color:inherit; }
    .lgg .lgg-toggles { display:flex; gap:8px; margin-left:auto; flex-wrap:wrap; }
    .lgg .lgg-toggle { font-size:12px; border:1px solid var(--lgg-line); border-radius:8px; padding:6px 11px; background:transparent; color:var(--lgg-muted); cursor:pointer; }
    .lgg .lgg-toggle.on { background:rgba(37,99,235,.12); color:#2563eb; border-color:rgba(37,99,235,.4); font-weight:600; }
    .lgg .lgg-hint { font-size:12.5px; color:var(--lgg-muted); }
    .lgg .lgg-warn { font-size:12px; color:#b45309; background:rgba(217,119,6,.10); border:1px solid rgba(217,119,6,.30); border-radius:8px; padding:8px 12px; margin-bottom:16px; }

    .lgg .lgg-wall { display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:16px; }
    .lgg .lgg-card { border:1px solid var(--lgg-line); border-radius:12px; padding:14px; background:var(--lgg-surface); cursor:pointer; transition:border-color .12s, transform .12s; }
    .lgg .lgg-card:hover { border-color:#2563eb; transform:translateY(-1px); }
    .lgg .lgg-kw { font-size:13px; font-weight:700; margin-bottom:10px; line-height:1.3; word-break:break-word; }
    .lgg .lgg-map { width:100%; aspect-ratio:1/1; display:block; margin:0 auto 12px; background:var(--lgg-surface2); border-radius:10px; }
    .lgg .lgg-town { stroke:rgba(255,255,255,.55); stroke-width:.35; stroke-linejoin:round; fill:var(--abs); }
    .dark .lgg .lgg-town { stroke:rgba(0,0,0,.45); }
    .lgg.mode-delta .lgg-town { fill:var(--delta); }
    .lgg .lgg-dot { stroke:rgba(0,0,0,.25); stroke-width:.4; fill:var(--abs); }
    .lgg.mode-delta .lgg-dot { fill:var(--delta); }
    .lgg .lgg-county { fill:none; stroke:#334155; stroke-width:.7; stroke-linejoin:round; opacity:.8; }
    .dark .lgg .lgg-county { stroke:#cbd5e1; }
    /* The map-pack position written into the town it belongs to — white with a dark outline so it reads on
       any fill, in either theme. */
    .lgg .lgg-rank { font-size:2.6px; font-weight:700; fill:#fff; stroke:rgba(0,0,0,.65); stroke-width:.55px;
                     paint-order:stroke fill; text-anchor:middle; dominant-baseline:central;
                     font-family:'Spline Sans Mono',ui-monospace,monospace; }
    .lgg .lgg-bigmap .lgg-rank { font-size:2.2px; stroke-width:.45px; }
    .lgg .lgg-stats { display:flex; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--lgg-muted); }
    .lgg .lgg-stats b { color:inherit; font-weight:700; font-variant-numeric:tabular-nums; }
    .lgg .lgg-chip { font-size:11px; font-weight:700; border-radius:999px; padding:2px 8px; }
    .lgg .lgg-chip.up { background:rgba(22,163,74,.16); color:#15803d; }
    .lgg .lgg-chip.down { background:rgba(220,38,38,.14); color:#c0392b; }
    .lgg .lgg-chip.flat { background:rgba(148,163,184,.16); color:var(--lgg-faint); }

    .lgg .lgg-overlay { position:fixed; inset:0; background:rgba(15,20,27,.55); display:flex; align-items:center; justify-content:center; padding:24px; z-index:50; }
    .lgg .lgg-modal { background:var(--lgg-surface); border:1px solid var(--lgg-line); border-radius:16px; padding:22px; max-width:min(640px, 95vw); max-height:92vh; overflow:auto; }
    .lgg .lgg-modal-head { display:flex; align-items:baseline; justify-content:space-between; gap:16px; margin-bottom:6px; }
    .lgg .lgg-modal-kw { font-size:16px; font-weight:800; }
    .lgg .lgg-close { border:none; background:transparent; font-size:20px; line-height:1; color:var(--lgg-muted); cursor:pointer; }
    .lgg .lgg-meta { font-size:12px; color:var(--lgg-muted); margin-bottom:16px; }
    .lgg .lgg-biggrid { display:grid; gap:3px; width:max-content; margin:0 auto 8px; }
    .lgg .lgg-bigcell { width:42px; height:42px; border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:#fff; font-variant-numeric:tabular-nums; background:var(--abs); text-shadow:0 1px 2px rgba(0,0,0,.35); }
    .lgg.mode-delta .lgg-bigcell { background:var(--delta); }
    .lgg .lgg-bigcell.absent { color:rgba(255,255,255,.75); }
    .lgg .lgg-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:11.5px; color:var(--lgg-muted); margin:12px 0 4px; }
    .lgg .lgg-legend i { display:inline-block; width:11px; height:11px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
    .lgg .lgg-comps { margin-top:14px; border-top:1px solid var(--lgg-line); padding-top:12px; }
    .lgg .lgg-comps h4 { font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:var(--lgg-muted); font-weight:700; margin-bottom:8px; }
    .lgg .lgg-comps ul { font-size:12.5px; color:inherit; margin:0; padding:0; list-style:none; }
    .lgg .lgg-comps li { padding:2px 0; }
    .lgg .lgg-comps li span { color:var(--lgg-faint); }
    .lgg .lgg-empty { padding:40px 16px; text-align:center; color:var(--lgg-muted); border:1px dashed var(--lgg-line); border-radius:12px; }
</style>

<div class="lgg" x-data="{ mode:'absolute', comps:false, open:null }" :class="{ 'mode-delta': mode==='delta' }">
    <div class="lgg-controls">
        <select class="lgg-select" wire:model.live="locationId" aria-label="Location">
            @forelse ($this->locations as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @empty
                <option value="">No GBP-backed locations</option>
            @endforelse
        </select>

        <div class="lgg-toggles">
            <button type="button" class="lgg-toggle" :class="{ on: mode==='absolute' }" @click="mode='absolute'">Absolute rank</button>
            <button type="button" class="lgg-toggle" :class="{ on: mode==='delta' }" @click="mode='delta'">Δ since last</button>
            <button type="button" class="lgg-toggle" :class="{ on: comps }" @click="comps=!comps">Competitors</button>
        </div>
    </div>

    <div class="lgg-warn">Internal test build — ATRP / SoLV are <b>uncalibrated</b> against Local Falcon. Trend directionally; do not publish these numbers.</div>

    @if ($board === null || $board['keyword_count'] === 0)
        <div class="lgg-empty">
            No map-pack scans for this location yet. Each scan is one Maps search per served town, from that
            town's own coordinates — run one from a keyword's card on Service Areas, or with
            <code>launchpad:coverage-scan</code>.
        </div>
    @else
        <p class="lgg-hint" style="margin-bottom:14px;">{{ $board['keyword_count'] }} keyword(s) across {{ $board['towns'] }} town(s) · worst ATRP first · click a card to expand.</p>

        <div class="lgg-wall">
            @foreach ($board['cards'] as $i => $card)
                @php($delta = $card['delta_atrp'])
                <div class="lgg-card" @click="open = {{ $i }}" role="button" tabindex="0" aria-label="Expand {{ $card['keyword'] }}">
                    <div class="lgg-kw">{{ $card['keyword'] }}</div>
                    <svg class="lgg-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img"
                         aria-label="Map-pack rank by town for {{ $card['keyword'] }}">
                        @foreach ($card['towns'] as $t)
                            @if (isset($board['town_paths'][$t['id']]))
                                @foreach ($board['town_paths'][$t['id']] as $d)
                                    <path class="lgg-town" d="{{ $d }}" style="--abs:{{ $t['color'] }};--delta:{{ $t['delta_color'] }};"><title>{{ $t['label'] }} — {{ $t['rank'] !== null ? '#'.$t['rank'] : 'absent' }}</title></path>
                                @endforeach
                            @else
                                <circle class="lgg-dot" cx="{{ $t['x'] }}" cy="{{ $t['y'] }}" r="1.4" style="--abs:{{ $t['color'] }};--delta:{{ $t['delta_color'] }};"><title>{{ $t['label'] }} — {{ $t['rank'] !== null ? '#'.$t['rank'] : 'absent' }}</title></circle>
                            @endif
                        @endforeach
                        @foreach ($board['outlines'] as $o)
                            @foreach ($o['paths'] as $d)
                                <path class="lgg-county" d="{{ $d }}" pointer-events="none"><title>{{ $o['label'] }}</title></path>
                            @endforeach
                        @endforeach
                        @foreach ($card['towns'] as $t)
                            @if ($t['rank'] !== null)
                                <text class="lgg-rank" x="{{ $t['x'] }}" y="{{ $t['y'] }}" pointer-events="none">{{ $t['rank'] }}</text>
                            @endif
                        @endforeach
                    </svg>
                    <div class="lgg-stats">
                        <span>ATRP <b>{{ $card['atrp'] !== null ? number_format($card['atrp'], 1) : '—' }}</b></span>
                        <span>SoLV <b>{{ $card['solv'] !== null ? number_format($card['solv'], 0).'%' : '—' }}</b></span>
                        <span>Found <b>{{ $card['found_rate'] !== null ? number_format($card['found_rate'], 0).'%' : '—' }}</b></span>
                        @if ($delta !== null)
                            {{-- Lower ATRP is better: a negative delta is an improvement. --}}
                            <span class="lgg-chip {{ $delta < 0 ? 'up' : ($delta > 0 ? 'down' : 'flat') }}">
                                {{ $delta < 0 ? '▲' : ($delta > 0 ? '▼' : '±') }} {{ number_format(abs($delta), 1) }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Expanded overlay — one per card, shown when open === index. --}}
        @foreach ($board['cards'] as $i => $card)
            <div class="lgg-overlay" x-show="open === {{ $i }}" x-cloak @click.self="open = null" @keydown.escape.window="open = null" style="display:none;">
                <div class="lgg-modal">
                    <div class="lgg-modal-head">
                        <div class="lgg-modal-kw">{{ $card['keyword'] }}</div>
                        <button type="button" class="lgg-close" @click="open = null" aria-label="Close">&times;</button>
                    </div>
                    <div class="lgg-meta">
                        {{ $board['towns'] }} town(s) · depth {{ $card['depth_cap'] }} ·
                        {{ ucfirst($card['status']) }} ·
                        scanned {{ $card['scanned_at'] ? \Illuminate\Support\Carbon::parse($card['scanned_at'])->diffForHumans() : '—' }}
                        @if ($card['prev_scanned_at']) · prev {{ \Illuminate\Support\Carbon::parse($card['prev_scanned_at'])->diffForHumans() }} @endif
                    </div>

                    <svg class="lgg-map lgg-bigmap" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet" role="img"
                         aria-label="Map-pack rank by town, expanded">
                        @foreach ($card['towns'] as $t)
                            @if (isset($board['town_paths'][$t['id']]))
                                @foreach ($board['town_paths'][$t['id']] as $d)
                                    <path class="lgg-town" d="{{ $d }}" style="--abs:{{ $t['color'] }};--delta:{{ $t['delta_color'] }};"><title>{{ $this->townTitle($t) }}</title></path>
                                @endforeach
                            @else
                                <circle class="lgg-dot" cx="{{ $t['x'] }}" cy="{{ $t['y'] }}" r="1.4" style="--abs:{{ $t['color'] }};--delta:{{ $t['delta_color'] }};"><title>{{ $this->townTitle($t) }}</title></circle>
                            @endif
                        @endforeach
                        @foreach ($board['outlines'] as $o)
                            @foreach ($o['paths'] as $d)
                                <path class="lgg-county" d="{{ $d }}" pointer-events="none"><title>{{ $o['label'] }}</title></path>
                            @endforeach
                        @endforeach
                        {{-- The position itself, over the town it was found in; an absent town carries no number. --}}
                        @foreach ($card['towns'] as $t)
                            @if ($t['rank'] !== null)
                                <text class="lgg-rank" x="{{ $t['x'] }}" y="{{ $t['y'] }}" pointer-events="none"
                                      x-show="mode==='absolute'">{{ $t['rank'] }}</text>
                                <text class="lgg-rank" x="{{ $t['x'] }}" y="{{ $t['y'] }}" pointer-events="none"
                                      x-show="mode==='delta'" x-cloak>{{ $t['move'] !== null ? ($t['move'] > 0 ? '+'.$t['move'] : $t['move']) : '•' }}</text>
                            @endif
                        @endforeach
                    </svg>

                    <div class="lgg-comps" style="margin-top:10px">
                        <h4>Every town, best position first</h4>
                        <ul>
                            @foreach ($card['towns'] as $t)
                                <li>
                                    <b style="font-variant-numeric:tabular-nums">{{ $t['rank'] !== null ? '#'.$t['rank'] : ($t['pending'] ? '…' : '—') }}</b>
                                    {{ $t['label'] }}
                                    <span>@if ($t['move'] !== null && $t['move'] !== 0)· {{ $t['move'] > 0 ? 'up '.$t['move'] : 'down '.abs($t['move']) }} @endif @if ($t['population'] > 0)· pop {{ number_format($t['population']) }}@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="lgg-legend">
                        <span x-show="mode==='absolute'"><i style="background:#15803d"></i>1–3</span>
                        <span x-show="mode==='absolute'"><i style="background:#65a30d"></i>4–7</span>
                        <span x-show="mode==='absolute'"><i style="background:#ca8a04"></i>8–10</span>
                        <span x-show="mode==='absolute'"><i style="background:#c2410c"></i>11–15</span>
                        <span x-show="mode==='absolute'"><i style="background:#c0392b"></i>16+</span>
                        <span x-show="mode==='absolute'"><i style="background:#9ca3af"></i>Not found</span>
                        <span x-show="mode==='delta'" x-cloak><i style="background:#15803d"></i>Improved</span>
                        <span x-show="mode==='delta'" x-cloak><i style="background:#c0392b"></i>Slipped</span>
                        <span x-show="mode==='delta'" x-cloak><i style="background:#2563eb"></i>New</span>
                        <span x-show="mode==='delta'" x-cloak><i style="background:#7f1d1d"></i>Lost</span>
                    </div>

                    {{-- Top-3 competitors at the strongest and weakest points, revealed by the competitors toggle. --}}
                    <div class="lgg-comps" x-show="comps" x-cloak>
                        <h4>Competitors on this grid (top by frequency)</h4>
                        @php($comps = $this->topCompetitors($card))
                        @if (empty($comps))
                            <p class="lgg-hint">No competitor data captured for this scan.</p>
                        @else
                            <ul>
                                @foreach ($comps as $c)
                                    <li>{{ $c['name'] }} <span>· seen at {{ $c['points'] }} point(s), best #{{ $c['best'] }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
</x-filament-panels::page>
