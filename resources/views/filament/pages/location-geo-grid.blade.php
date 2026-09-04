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
    .lgg .lgg-thumb { display:grid; gap:2px; margin:0 auto 12px; width:max-content; }
    .lgg .lgg-cell { width:16px; height:16px; border-radius:2px; background:var(--abs); }
    .lgg.mode-delta .lgg-cell { background:var(--delta); }
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
            No geo-grid scans for this location yet. Flag keywords with <code>is_grid_keyword</code> and run
            <code>launchpad:geo-grid-scan</code> for a GBP-backed, grid-ready location.
        </div>
    @else
        <p class="lgg-hint" style="margin-bottom:14px;">{{ $board['keyword_count'] }} keyword grid(s) · worst ATRP first · click a card to expand.</p>

        <div class="lgg-wall">
            @foreach ($board['cards'] as $i => $card)
                @php($delta = $card['delta_atrp'])
                <div class="lgg-card" @click="open = {{ $i }}" role="button" tabindex="0" aria-label="Expand {{ $card['keyword'] }}">
                    <div class="lgg-kw">{{ $card['keyword'] }}</div>
                    <div class="lgg-thumb" style="grid-template-columns:repeat({{ $card['grid_size'] }}, 16px);">
                        @foreach ($card['matrix'] as $row)
                            @foreach ($row as $cell)
                                <div class="lgg-cell" style="--abs:{{ $cell['absolute_color'] }};--delta:{{ $cell['delta_color'] }};"></div>
                            @endforeach
                        @endforeach
                    </div>
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
                        {{ $card['grid_size'] }}×{{ $card['grid_size'] }} · depth {{ $card['depth_cap'] }} ·
                        {{ ucfirst($card['status']) }} ·
                        scanned {{ $card['scanned_at'] ? \Illuminate\Support\Carbon::parse($card['scanned_at'])->diffForHumans() : '—' }}
                        @if ($card['prev_scanned_at']) · prev {{ \Illuminate\Support\Carbon::parse($card['prev_scanned_at'])->diffForHumans() }} @endif
                    </div>

                    <div class="lgg-biggrid" style="grid-template-columns:repeat({{ $card['grid_size'] }}, 42px);">
                        @foreach ($card['matrix'] as $row)
                            @foreach ($row as $cell)
                                @php($title = $this->cellTitle($cell))
                                <div class="lgg-bigcell {{ $cell['rank'] === null ? 'absent' : '' }}"
                                     style="--abs:{{ $cell['absolute_color'] }};--delta:{{ $cell['delta_color'] }};"
                                     title="{{ $title }}">
                                    <span x-show="mode==='absolute'">{{ $cell['rank'] ?? '·' }}</span>
                                    <span x-show="mode==='delta'" x-cloak>{{ $cell['move'] !== null ? ($cell['move'] > 0 ? '+'.$cell['move'] : $cell['move']) : ($cell['rank'] === null ? '·' : '•') }}</span>
                                </div>
                            @endforeach
                        @endforeach
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
