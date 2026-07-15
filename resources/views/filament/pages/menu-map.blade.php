<x-filament-panels::page>
    <style>
        .mm-wrap { display:flex; flex-direction:column; gap:14px; }
        .mm-sub { color:#64748b; font-size:13px; max-width:70ch; margin:0; }
        .mm-counts { display:flex; gap:14px; flex-wrap:wrap; font-size:12.5px; color:#64748b; }
        .mm-counts b { font-variant-numeric:tabular-nums; }
        .mm-dupes { border:1px solid rgba(217,119,6,.45); border-radius:10px; padding:10px 14px; font-size:12.5px; color:#b45309; line-height:1.6; }
        .mm-dupes strong { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
        .mm-group { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; }
        .mm-ghead { display:flex; align-items:center; gap:10px; padding:9px 14px; background:rgba(148,163,184,.08); border-bottom:1px solid rgba(148,163,184,.2); }
        .mm-ghead h3 { margin:0; font-size:13.5px; }
        .mm-gcount { margin-left:auto; font-size:11px; color:#94a3b8; font-variant-numeric:tabular-nums; }
        .mm-row { display:flex; align-items:center; gap:10px; padding:7px 14px; border-bottom:1px solid rgba(148,163,184,.13); font-size:13px; flex-wrap:wrap; }
        .mm-row:last-child { border-bottom:0; }
        .mm-sort { width:28px; text-align:right; font-size:11px; color:#94a3b8; font-variant-numeric:tabular-nums; flex:none; }
        .mm-label { font-weight:600; min-width:160px; }
        .mm-label a { text-decoration:none; }
        .mm-url { font-size:11.5px; color:#94a3b8; font-family:ui-monospace, monospace; }
        .mm-chips { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
        .mm-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:1px 8px; border-radius:99px; background:rgba(148,163,184,.15); color:#64748b; }
        .mm-chip.setup { background:rgba(79,70,229,.12); color:#6366f1; }
        .mm-chip.operate { background:rgba(22,163,74,.12); color:#16a34a; }
        .mm-chip.hidden { background:rgba(220,38,38,.12); color:#dc2626; }
    </style>

    <div class="mm-wrap">
        @php $map = $this->map; @endphp

        <p class="mm-sub">Every admin surface, enumerated from the panel itself (nothing hand-listed) with both new-menu flags treated as ON — the full inventory for deciding the one final menu. Numbers on the left are each item's current sort within its group; red "hidden" chips are superseded routes kept alive; amber duplicates below need a naming or cutover decision.</p>

        <div class="mm-counts">
            <span><b>{{ $map['counts']['total'] }}</b> surfaces total</span>
            <span><b>{{ $map['counts']['visible'] }}</b> in the nav (flags on)</span>
            <span><b>{{ $map['counts']['hidden'] }}</b> hidden (routes kept)</span>
            <span><b>{{ $map['counts']['flagged'] }}</b> flag-gated</span>
        </div>

        @if ($map['duplicates'] !== [])
            <div class="mm-dupes">
                <strong>Same label in multiple places</strong>
                @foreach ($map['duplicates'] as $dupe)
                    {{ $dupe }}@if (! $loop->last)<br>@endif
                @endforeach
            </div>
        @endif

        @foreach ($map['groups'] as $group)
            <div class="mm-group" wire:key="mm-{{ \Illuminate\Support\Str::slug($group['group']) }}">
                <div class="mm-ghead">
                    <h3>{{ $group['group'] }}</h3>
                    <span class="mm-gcount">{{ count($group['items']) }} item(s)</span>
                </div>
                @foreach ($group['items'] as $item)
                    <div class="mm-row">
                        <span class="mm-sort">{{ $item['sort'] }}</span>
                        <span class="mm-label">
                            @if ($item['url'] && ! $item['hidden'])
                                <a href="{{ $item['url'] }}" wire:navigate>{{ $item['label'] }}</a>
                            @else
                                {{ $item['label'] }}
                            @endif
                        </span>
                        @if ($item['url'])
                            <span class="mm-url">{{ parse_url($item['url'], PHP_URL_PATH) }}</span>
                        @endif
                        <span class="mm-chips">
                            @if ($item['flag'] === 'NEW_SETUP')<span class="mm-chip setup">new setup</span>@endif
                            @if ($item['flag'] === 'NEW_OPERATE')<span class="mm-chip operate">new operate</span>@endif
                            @if ($item['tag'] ?? null)<span class="mm-chip operate">{{ $item['tag'] }}</span>@endif
                            @if ($item['hidden'])<span class="mm-chip hidden">hidden</span>@endif
                            <span class="mm-chip">{{ $item['kind'] }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
