<x-filament-panels::page>
    <style>
        .tg-wrap { display:flex; flex-direction:column; gap:16px; }
        .tg-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
        .tg-sub { color:#64748b; font-size:13px; max-width:64ch; margin:4px 0 0; }
        .tg-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:3px 8px; background:transparent; }
        .tg-links { font-size:12px; color:#64748b; display:flex; gap:14px; flex-wrap:wrap; }
        .tg-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:12px; }
        .tg-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; display:flex; flex-direction:column; }
        .tg-cardhead { display:flex; align-items:center; gap:10px; padding:12px 14px; background:rgba(148,163,184,.07); border-bottom:1px solid rgba(148,163,184,.25); flex-wrap:wrap; }
        .tg-cardhead h3 { margin:0; font-size:15px; }
        .tg-badge { font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; padding:2px 8px; border-radius:99px; }
        .tg-badge.ok { background:rgba(22,163,74,.12); color:#16a34a; }
        .tg-badge.thin { background:rgba(217,119,6,.14); color:#b45309; }
        .tg-split { margin-left:auto; font-size:11.5px; color:#64748b; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .tg-warn { padding:7px 14px; font-size:12px; color:#b45309; background:rgba(217,119,6,.07); border-bottom:1px solid rgba(148,163,184,.2); }
        .tg-rows { display:flex; flex-direction:column; }
        .tg-row { display:flex; align-items:center; gap:9px; padding:7px 14px; border-bottom:1px solid rgba(148,163,184,.15); font-size:12.5px; }
        .tg-row:last-child { border-bottom:0; }
        .tg-q { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .tg-num { color:#64748b; font-variant-numeric:tabular-nums; font-size:11.5px; white-space:nowrap; }
        .tg-cov { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:1px 7px; border-radius:5px; }
        .tg-cov.gap { background:rgba(217,119,6,.14); color:#b45309; }
        .tg-cov.covered { background:rgba(22,163,74,.12); color:#16a34a; }
        .tg-pri { font-size:11px; color:#64748b; font-variant-numeric:tabular-nums; min-width:18px; text-align:center; }
        .tg-btn { font-size:11px; padding:1px 7px; border-radius:6px; border:1px solid rgba(148,163,184,.4); background:transparent; cursor:pointer; line-height:1.4; }
        .tg-more { padding:8px 14px 11px; font-size:12px; }
        .tg-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:14px 16px; color:#94a3b8; font-size:13px; }
    </style>

    <div class="tg-wrap">
        <div class="tg-head">
            <div>
                <p class="tg-sub">What the engine targets, silo by silo — each card is a silo with its keyword targets in queue order (your priority first, then opportunity). Promote/demote reorders the directed lane; a thin silo is flagged before it ever holds a lone page.</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <select class="tg-select" wire:change="setSite($event.target.value)">
                    @foreach ($this->siteOptions as $id => $label)
                        <option value="{{ $id }}" @selected($id === $this->siteId)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @php $board = $this->board; @endphp

        <div class="tg-links">
            <a href="{{ \App\Filament\Resources\KeywordResource::getUrl('index') }}" wire:navigate>Full keyword table →</a>
            <a href="{{ \App\Filament\Resources\SiloManagementResource::getUrl('index') }}" wire:navigate>Silo table (create / edit) →</a>
        </div>

        @if ($board['silos'] === [])
            <div class="tg-empty">No silos yet — the structure builds during setup (Your website plan); keyword targets attach as §5 discovery runs.</div>
        @else
            <div class="tg-grid">
                @foreach ($board['silos'] as $silo)
                    <div class="tg-card" wire:key="tg-{{ $silo['id'] }}">
                        <div class="tg-cardhead">
                            <h3>{{ $silo['name'] }}</h3>
                            <span class="tg-badge {{ $silo['viable'] ? 'ok' : 'thin' }}">{{ $silo['viable'] ? 'viable' : 'thin' }}</span>
                            <span class="tg-split">{{ $silo['covered'] }} covered · {{ $silo['gaps'] }} gaps</span>
                        </div>
                        @if ($silo['warning'] !== null)
                            <div class="tg-warn">{{ $silo['warning'] }}</div>
                        @endif
                        <div class="tg-rows">
                            @forelse ($silo['keywords'] as $kw)
                                <div class="tg-row" wire:key="tgk-{{ $kw['id'] }}">
                                    <span class="tg-q" title="{{ $kw['query'] }}">{{ $kw['query'] }}</span>
                                    <span class="tg-num">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                                    <span class="tg-num">{{ $kw['opportunity'] !== null ? number_format($kw['opportunity'], 2) : '—' }}</span>
                                    <span class="tg-cov {{ $kw['covered'] ? 'covered' : 'gap' }}">{{ $kw['covered'] ? 'covered' : 'gap' }}</span>
                                    <span class="tg-pri" title="Priority override">{{ $kw['priority'] }}</span>
                                    <button type="button" class="tg-btn" title="Promote" wire:click="promote('{{ $kw['id'] }}')">▲</button>
                                    <button type="button" class="tg-btn" title="Demote" wire:click="demote('{{ $kw['id'] }}')">▼</button>
                                </div>
                            @empty
                                <div class="tg-row" style="color:#94a3b8">No keyword targets yet — §5 discovery fills this.</div>
                            @endforelse
                        </div>
                        @if ($silo['total'] > count($silo['keywords']))
                            <div class="tg-more">
                                <a href="{{ \App\Filament\Resources\KeywordResource::getUrl('index') }}" wire:navigate>+ {{ $silo['total'] - count($silo['keywords']) }} more targets →</a>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($board['unassigned_total'] > 0)
            <div class="tg-card">
                <div class="tg-cardhead">
                    <h3>Unassigned keywords</h3>
                    <span class="tg-badge thin">no silo</span>
                    <span class="tg-split">{{ $board['unassigned_total'] }} total</span>
                </div>
                <div class="tg-rows">
                    @foreach ($board['unassigned'] as $kw)
                        <div class="tg-row" wire:key="tgu-{{ $kw['id'] }}">
                            <span class="tg-q" title="{{ $kw['query'] }}">{{ $kw['query'] }}</span>
                            <span class="tg-num">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                            <span class="tg-cov {{ $kw['covered'] ? 'covered' : 'gap' }}">{{ $kw['covered'] ? 'covered' : 'gap' }}</span>
                            <button type="button" class="tg-btn" title="Promote" wire:click="promote('{{ $kw['id'] }}')">▲</button>
                            <button type="button" class="tg-btn" title="Demote" wire:click="demote('{{ $kw['id'] }}')">▼</button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
