<x-filament-panels::page>
    <style>
        .nm-wrap { display:flex; flex-direction:column; gap:14px; }
        .nm-sub { color:#64748b; font-size:13px; max-width:70ch; margin:0; }
        .nm-counts { display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px; color:#64748b; }
        .nm-counts b { font-variant-numeric:tabular-nums; }
        .nm-group { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; }
        .nm-group.final { border-color:rgba(79,70,229,.45); }
        .nm-ghead { display:flex; align-items:center; gap:10px; padding:9px 14px; background:rgba(148,163,184,.08); border-bottom:1px solid rgba(148,163,184,.2); }
        .nm-group.final .nm-ghead { background:rgba(79,70,229,.08); }
        .nm-ghead h3 { margin:0; font-size:13.5px; }
        .nm-gcount { margin-left:auto; font-size:11px; color:#94a3b8; font-variant-numeric:tabular-nums; }
        .nm-row { display:flex; align-items:center; gap:10px; padding:7px 14px; border-bottom:1px solid rgba(148,163,184,.13); font-size:13px; flex-wrap:wrap; }
        .nm-row:last-child { border-bottom:0; }
        .nm-sort { width:28px; text-align:right; font-size:11px; color:#94a3b8; font-variant-numeric:tabular-nums; flex:none; }
        .nm-label { font-weight:600; min-width:170px; }
        .nm-label a { text-decoration:none; }
        .nm-url { font-size:11.5px; color:#94a3b8; font-family:ui-monospace, monospace; }
        .nm-chips { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
        .nm-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:1px 8px; border-radius:99px; background:rgba(148,163,184,.15); color:#64748b; }
        .nm-chip.decide { background:rgba(217,119,6,.14); color:#b45309; }
        .nm-chip.retire { background:rgba(220,38,38,.1); color:#dc2626; }
        .nm-note { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin:6px 0 -6px; }
    </style>

    <div class="nm-wrap">
        @php $m = $this->menu; @endphp

        <p class="nm-sub">The proposed FINAL menu — only the newly designed surfaces, in cutover order, derived live from the same enumeration as the Menu map. This is the studio-rebuild worksheet: every link below is a page the design pass covers. Below the line: the open decisions, then everything that leaves the sidebar at cutover.</p>

        <div class="nm-counts">
            <span><b>{{ $m['counts']['menu'] }}</b> in the final menu</span>
            <span><b>{{ $m['counts']['pending'] }}</b> pending decision</span>
            <span><b>{{ $m['counts']['retiring'] }}</b> retiring at cutover</span>
            <span><b>{{ $m['counts']['drilldowns'] }}</b> drill-downs (no menu entry)</span>
        </div>

        {{-- ── The final menu ── --}}
        @foreach ($m['menu'] as $group)
            <div class="nm-group final" wire:key="nm-{{ \Illuminate\Support\Str::slug($group['group']) }}">
                <div class="nm-ghead">
                    <h3>{{ $group['group'] }}</h3>
                    <span class="nm-gcount">{{ count($group['items']) }} item(s)</span>
                </div>
                @foreach ($group['items'] as $item)
                    <div class="nm-row">
                        <span class="nm-sort">{{ $item['sort'] }}</span>
                        <span class="nm-label">
                            @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate>{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif
                        </span>
                        @if ($item['url'])<span class="nm-url">{{ parse_url($item['url'], PHP_URL_PATH) }}</span>@endif
                        <span class="nm-chips"><span class="nm-chip">{{ $item['kind'] }}</span></span>
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- ── Pending decisions ── --}}
        <div class="nm-note">Pending decisions — place, rebuild, or retire</div>
        <div class="nm-group">
            @foreach ($m['pending'] as $item)
                <div class="nm-row" wire:key="nmp-{{ \Illuminate\Support\Str::slug($item['label']) }}">
                    <span class="nm-sort"></span>
                    <span class="nm-label">
                        @if ($item['url'])<a href="{{ $item['url'] }}" wire:navigate>{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif
                    </span>
                    @if ($item['url'])<span class="nm-url">{{ parse_url($item['url'], PHP_URL_PATH) }}</span>@endif
                    <span class="nm-chips">
                        <span class="nm-chip decide">decide</span>
                        <span class="nm-chip">{{ $item['kind'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        {{-- ── Retiring at cutover ── --}}
        <div class="nm-note">Retiring at cutover — superseded legacy (routes stay)</div>
        <div class="nm-group">
            @foreach ($m['retiring'] as $item)
                <div class="nm-row" wire:key="nmr-{{ \Illuminate\Support\Str::slug($item['group'].'-'.$item['label']) }}">
                    <span class="nm-sort"></span>
                    <span class="nm-label">{{ $item['label'] }}</span>
                    <span class="nm-url">{{ $item['group'] }}</span>
                    <span class="nm-chips">
                        <span class="nm-chip retire">retire</span>
                        <span class="nm-chip">{{ $item['tag'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        {{-- ── Drill-downs ── --}}
        <div class="nm-note">Drill-downs — no menu entry, linked from inside surfaces</div>
        <div class="nm-group">
            @foreach ($m['drilldowns'] as $item)
                <div class="nm-row" wire:key="nmd-{{ \Illuminate\Support\Str::slug($item['group'].'-'.$item['label']) }}">
                    <span class="nm-sort"></span>
                    <span class="nm-label">{{ $item['label'] }}</span>
                    @if ($item['url'])<span class="nm-url">{{ parse_url($item['url'], PHP_URL_PATH) }}</span>@endif
                    <span class="nm-chips"><span class="nm-chip">{{ $item['kind'] }}</span></span>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
