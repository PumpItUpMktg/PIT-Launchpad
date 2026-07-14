<x-filament-panels::page>
    <style>
        .op-wrap { display:flex; flex-direction:column; gap:16px; }
        .op-sub { color:#64748b; font-size:13px; max-width:64ch; margin:0; }
        .op-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
        .op-tile { border:1px solid rgba(148,163,184,.35); border-radius:10px; padding:12px 14px; }
        .op-tile .n { font-size:24px; font-weight:700; font-variant-numeric:tabular-nums; line-height:1.15; }
        .op-tile .l { font-size:11px; color:#94a3b8; }
        .op-tile.hot { border-color:rgba(220,38,38,.5); } .op-tile.hot .n { color:#dc2626; }
        .op-row { border:1px solid rgba(148,163,184,.35); border-radius:11px; padding:12px 16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .op-row h3 { margin:0; font-size:14.5px; min-width:160px; }
        .op-chip { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:3px 11px; border-radius:99px;
            background:rgba(217,119,6,.12); color:#b45309; text-decoration:none; }
        .op-chip.danger { background:rgba(220,38,38,.12); color:#dc2626; }
        .op-chip:hover { filter:brightness(1.1); }
        .op-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:16px; color:#94a3b8; font-size:13.5px; text-align:center; }
    </style>

    <div class="op-wrap">
        <p class="op-sub">Attention items only — every number is work, every chip opens the filtered surface. A tenant with nothing to do isn't listed. Work it to zero.</p>

        @php $board = $this->board; $totals = $board['totals']; @endphp

        <div class="op-tiles">
            <div class="op-tile {{ $totals['review'] > 0 ? 'hot' : '' }}"><div class="n">{{ $totals['review'] }}</div><div class="l">drafts awaiting review</div></div>
            <div class="op-tile"><div class="n">{{ $totals['candidates'] }}</div><div class="l">candidates to triage</div></div>
            <div class="op-tile {{ $totals['failures'] > 0 ? 'hot' : '' }}"><div class="n">{{ $totals['failures'] }}</div><div class="l">failed pushes</div></div>
            <div class="op-tile"><div class="n">{{ $totals['starved_queues'] }}</div><div class="l">starved blog queues</div></div>
            <div class="op-tile"><div class="n">{{ $totals['stale_feeds'] }}</div><div class="l">stale feeds</div></div>
            <div class="op-tile"><div class="n">{{ $totals['setup_gaps'] }}</div><div class="l">setup gaps</div></div>
        </div>

        @forelse ($board['rows'] as $row)
            <div class="op-row" wire:key="op-{{ $row['site_id'] }}">
                <h3>{{ $row['tenant'] }}</h3>
                @foreach ($row['items'] as $item)
                    <a class="op-chip {{ $item['key'] === 'failures' ? 'danger' : '' }}"
                       href="{{ $this->urlFor($item['key'], $row['site_id']) }}" wire:navigate>
                        {{ $item['count'] }} {{ $item['label'] }}
                    </a>
                @endforeach
            </div>
        @empty
            <div class="op-empty">Every tenant is clean — nothing needs you right now.</div>
        @endforelse
    </div>
</x-filament-panels::page>
