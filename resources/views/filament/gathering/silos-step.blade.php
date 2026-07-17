<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'The generate phase: steps 1–6 gathered the business — this turns it into the structure everything downstream builds on. Generate the silo tree from the trade + services, then prune (fold / route / drop) and finalize. Routed longtails land in the blog target queue Operate consumes.'])

        <style>
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
        </style>

        @if ($pruneMode && $started)
            {{-- ── Prune mode: fold / route / drop, then finalize (the mode, not a menu item) ── --}}
            <div class="g-row" style="justify-content:space-between">
                <div class="g-muted">Prune mode — route every candidate, then Finalize to lock the structure.</div>
                <button type="button" class="g-btn" wire:click="closePrune">← Back to targets</button>
            </div>

            @if ($finalized)
                <div class="g-card" style="border-color:rgba(22,163,74,.4); background:rgba(22,163,74,.06)">
                    <h3>Structure confirmed</h3>
                    <p class="g-hint">The directed-coverage layer is locked — this is the page inventory generation builds from. Routed longtails are queued for the blog lane.</p>
                    <button type="button" class="g-btn primary" wire:click="closePrune">Back to targets</button>
                </div>
            @else
                @include('filament.pages.partials.prune-surface')
            @endif
        @else
            {{-- ── Generate ── --}}
            <div class="g-card">
                <h3>Structure</h3>
                @if (! $this->hasSeed)
                    <div class="g-empty">No seed yet — capture the trade on the Business step (or run the interview). The silo tree is generated from it plus the stated services.</div>
                @elseif (! $this->hasSpokes)
                    <p class="g-hint">Seed ready ({{ $this->structureStatus === 'failed' ? 'last generation failed — retry below' : 'trade + services gathered' }}). Generation expands the seed into the candidate tree, grounds it on search volume, and auto-arranges it.</p>
                    <button type="button" class="g-btn primary" wire:click="generate" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="generate">⚙ Generate structure</span>
                        <span wire:loading wire:target="generate">Generating…</span>
                    </button>
                @else
                    <div class="g-row" style="justify-content:space-between">
                        <div>
                            <span class="g-seed {{ $this->blueprintConfirmed ? 'confirmed' : '' }}">{{ $this->blueprintConfirmed ? 'confirmed' : 'candidate' }}</span>
                            <span class="g-muted" style="margin-left:6px">
                                {{ $this->blueprintConfirmed ? 'Structure finalized — prune again after a real business change.' : 'Candidate tree generated — prune & finalize to lock it.' }}
                                @if ($this->blogTargetCount > 0)
                                    {{ $this->blogTargetCount }} blog target(s) queued for Operate → Blog.
                                @endif
                            </span>
                        </div>
                        <div class="g-row">
                            <button type="button" class="g-btn" wire:click="generate" wire:loading.attr="disabled"
                                wire:confirm="Re-ground and re-arrange the tree? Confirmed decisions are preserved; volumes and arrangement refresh.">
                                <span wire:loading.remove wire:target="generate">↻ Re-ground & re-arrange</span>
                                <span wire:loading wire:target="generate">Running…</span>
                            </button>
                            <button type="button" class="g-btn primary" wire:click="openPrune">✂ {{ $this->blueprintConfirmed ? 'Re-prune' : 'Prune & finalize' }}</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- ── The generated tree (read-only — what generate / re-ground produced; prune edits) ── --}}
            @if ($this->hasSpokes)
                <div class="g-muted" style="font-size:11px; text-transform:uppercase; letter-spacing:.05em">Generated structure — {{ count($this->tree) }} silo(s)</div>
                <div class="tg-grid">
                    @foreach ($this->tree as $siloName => $rows)
                        @php
                            $sorted = collect($rows)->sortBy([['isPillar', 'desc'], ['volume', 'desc']])->values();
                            $pageCount = $sorted->filter(fn ($r) => $r->isPillar || $r->granularity->value === 'own_page')->count();
                        @endphp
                        <div class="tg-card" wire:key="tree-{{ \Illuminate\Support\Str::slug($siloName) }}">
                            <div class="tg-cardhead">
                                <h3>{{ $siloName }}</h3>
                                <span class="tg-split">{{ $pageCount }} page(s) · {{ $sorted->count() }} topic(s)</span>
                            </div>
                            <div class="tg-rows">
                                @foreach ($sorted->take(10) as $row)
                                    <div class="tg-row" wire:key="tr-{{ $row->id }}">
                                        <span class="tg-q" title="{{ $row->name }}">{{ $row->isPillar ? '⬡ ' : '' }}{{ $row->name }}</span>
                                        <span class="tg-num">{{ $row->volume !== null ? number_format((int) $row->volume) : '—' }}</span>
                                        <span class="tg-cov {{ $row->isPillar || $row->granularity->value === 'own_page' ? 'covered' : 'gap' }}">
                                            {{ $row->isPillar ? 'hub page' : match ($row->granularity->value) {
                                                'own_page' => 'own page',
                                                'blog_target' => 'blog queue',
                                                default => 'section',
                                            } }}
                                        </span>
                                    </div>
                                @endforeach
                                @if ($sorted->count() > 10)
                                    <div class="tg-more">+ {{ $sorted->count() - 10 }} more — open Prune to see everything</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- ── Silo cards: keyword targets per silo, covered/gap, promote/demote ── --}}
            @php $board = $this->board; @endphp

            @if ($board['silos'] === [])
                @if ($this->hasSpokes)
                    <div class="g-muted">Keyword targets attach after launch, as discovery runs — the tree above is what gets built.</div>
                @else
                    <div class="g-empty">No structure yet — generate it above; keyword targets attach as discovery runs.</div>
                @endif
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
                                    <div class="tg-row" style="color:#94a3b8">No keyword targets yet — discovery fills this.</div>
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

            <div class="g-muted">
                Drill-downs:
                <a href="{{ \App\Filament\Resources\KeywordResource::getUrl('index') }}" wire:navigate>full keyword table</a> ·
                <a href="{{ \App\Filament\Resources\SiloManagementResource::getUrl('index') }}" wire:navigate>silo table</a>.
                The continuous half — the blog target queue — lives in Operate → Blog (the targets drawer).
            </div>
        @endif
    </div>
</x-filament-panels::page>
