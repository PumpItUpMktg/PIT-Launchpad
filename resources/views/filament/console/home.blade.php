<x-filament-panels::page>
    <style>
        .ch-wrap { display:flex; flex-direction:column; gap:18px; }
        .ch-tier { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .ch-badge { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:700; padding:5px 13px; border-radius:99px;
            background:rgba(99,102,241,.12); color:#4f46e5; }
        .ch-badge.super { background:rgba(22,163,74,.14); color:#15803d; }
        .ch-sub { color:#64748b; font-size:13.5px; max-width:70ch; margin:0; }
        .ch-groups { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; }
        .ch-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:10px; }
        .ch-card h3 { margin:0; font-size:15px; }
        .ch-card .blurb { color:#94a3b8; font-size:12px; margin:-4px 0 2px; }
        .ch-item { display:flex; align-items:center; gap:9px; font-size:13.5px; }
        .ch-dot { width:16px; height:16px; border-radius:99px; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; flex:0 0 auto; }
        .ch-dot.on { background:rgba(22,163,74,.16); color:#15803d; }
        .ch-dot.off { background:rgba(148,163,184,.2); color:#94a3b8; }
        .ch-item.off { color:#94a3b8; }
    </style>

    @php $board = $this->board; @endphp

    <div class="ch-wrap">
        <div class="ch-tier">
            <span class="ch-badge {{ $board['is_super'] ? 'super' : '' }}">
                {{ $board['is_super'] ? 'Super Admin' : $board['tier'] }}
            </span>
            <p class="ch-sub">
                @if ($board['is_super'])
                    You have full authority here, including the backend corrections a Site Admin can't reach.
                @else
                    You can run your site end to end. Backend corrections stay with the Super Admin team.
                @endif
            </p>
        </div>

        <div class="ch-groups">
            @foreach ($board['groups'] as $group)
                <div class="ch-card" wire:key="chg-{{ $group['key'] }}">
                    <h3>{{ $group['label'] }}</h3>
                    <div class="blurb">{{ $group['blurb'] }}</div>
                    @foreach ($group['items'] as $item)
                        <div class="ch-item {{ $item['held'] ? '' : 'off' }}">
                            <span class="ch-dot {{ $item['held'] ? 'on' : 'off' }}">{{ $item['held'] ? '✓' : '✕' }}</span>
                            {{ $item['label'] }}
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
