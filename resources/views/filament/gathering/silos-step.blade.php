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
            .tg-sel { font-size:11px; padding:1px 4px; border-radius:6px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; cursor:pointer; max-width:118px; }
            .tg-more { padding:8px 14px 11px; font-size:12px; }
            .tg-subhead { display:flex; align-items:center; gap:8px; padding:8px 14px 4px; font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; color:#64748b; }
            .tg-subhead .tg-split { margin-left:auto; }
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
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;margin-bottom:10px;cursor:pointer">
                        <input type="checkbox" wire:click="toggleBoundToServices" @checked($this->boundToServices)>
                        Bind to my stated services only — don't invent silos I don't offer
                    </label>
                    <div>
                        <button type="button" class="g-btn primary" wire:click="generate" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="generate">⚙ Generate structure</span>
                            <span wire:loading wire:target="generate">Generating…</span>
                        </button>
                    </div>
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
                        <div class="g-row" style="align-items:center">
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer" title="Bounded generation organizes ONLY your stated services into silos — no invented ones. Regenerate to apply.">
                                <input type="checkbox" wire:click="toggleBoundToServices" @checked($this->boundToServices)>
                                Stated services only
                            </label>
                            <button type="button" class="g-btn" wire:click="generate" wire:loading.attr="disabled"
                                wire:confirm="Re-ground and re-arrange the tree? Confirmed decisions are preserved; volumes and arrangement refresh.">
                                <span wire:loading.remove wire:target="generate">↻ Re-ground & re-arrange</span>
                                <span wire:loading wire:target="generate">Running…</span>
                            </button>
                            <button type="button" class="g-btn" wire:click="rebuildStructure" wire:loading.attr="disabled"
                                title="Clears the current tree and regenerates from scratch — the only way a changed seed or 'Stated services only' takes effect."
                                wire:confirm="Rebuild the tree FROM SCRATCH? This drops the current candidate tree (and its queued blog targets) and re-runs the AI — needed for 'Stated services only' to apply.">
                                <span wire:loading.remove wire:target="rebuildStructure">⟳ Rebuild from scratch</span>
                                <span wire:loading wire:target="rebuildStructure">Rebuilding…</span>
                            </button>
                            <button type="button" class="g-btn primary" wire:click="openPrune">✂ {{ $this->blueprintConfirmed ? 'Re-prune' : 'Prune & finalize' }}</button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- ── Silos: one card per silo — its PAGES (the generated tree) and its KEYWORD TARGETS
                 (what discovery fills) together, so the two aren't shown as duplicate sections. ── --}}
            @php
                $board = $this->board;
                $boardBySilo = collect($board['silos'])->keyBy('name');
                $treeNames = array_keys($this->tree);
            @endphp

            @if ($this->hasSpokes)
                <div class="g-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:4px 0 8px">
                    <div class="g-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Silos — {{ count($this->tree) }} · pages + keyword targets</div>
                    <button type="button" class="g-btn" wire:click="discoverKeywords"
                        wire:confirm="Run keyword discovery for this site? It fills each silo's targets from DataForSEO (may take a moment).">
                        ⌕ Discover keywords
                    </button>
                </div>

                <div class="tg-grid">
                    @foreach ($this->tree as $siloName => $rows)
                        @php
                            $sorted = collect($rows)->sortBy([['isPillar', 'desc'], ['volume', 'desc']])->values();
                            $pageCount = $sorted->filter(fn ($r) => $r->isPillar || $r->granularity->value === 'own_page')->count();
                            $b = $boardBySilo[$siloName] ?? null;
                        @endphp
                        <div class="tg-card" wire:key="silo-{{ \Illuminate\Support\Str::slug($siloName) }}">
                            <div class="tg-cardhead">
                                <h3>{{ $siloName }}</h3>
                                @if ($b)
                                    <span class="tg-badge {{ $b['viable'] ? 'ok' : 'thin' }}">{{ $b['viable'] ? 'viable' : 'thin' }}</span>
                                @endif
                                <span class="tg-split">{{ $pageCount }} page(s) · {{ $sorted->count() }} topic(s)</span>
                            </div>

                            {{-- Pages (the structure the build produces) --}}
                            <div class="tg-subhead">Pages</div>
                            <div class="tg-rows">
                                @foreach ($sorted->take(8) as $row)
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
                                @if ($sorted->count() > 8)
                                    <div class="tg-more">+ {{ $sorted->count() - 8 }} more — open Prune to see everything</div>
                                @endif
                            </div>

                            {{-- Keyword targets (what discovery routes into this silo) --}}
                            <div class="tg-subhead">
                                Keyword targets
                                @if ($b)<span class="tg-split">{{ $b['covered'] }} covered · {{ $b['gaps'] }} gaps</span>@endif
                            </div>
                            @if ($b && $b['warning'] !== null)
                                <div class="tg-warn">{{ $b['warning'] }}</div>
                            @endif
                            <div class="tg-rows">
                                @forelse ($b['keywords'] ?? [] as $kw)
                                    <div class="tg-row" wire:key="tgk-{{ $kw['id'] }}">
                                        <span class="tg-q" title="{{ $kw['query'] }}">{{ $kw['query'] }}</span>
                                        <span class="tg-num">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                                        <span class="tg-num">{{ $kw['opportunity'] !== null ? number_format($kw['opportunity'], 2) : '—' }}</span>
                                        <span class="tg-cov {{ $kw['covered'] ? 'covered' : 'gap' }}">{{ $kw['covered'] ? 'covered' : 'gap' }}</span>
                                        <span class="tg-pri" title="Priority override">{{ $kw['priority'] }}</span>
                                        <select class="tg-sel" title="Move this keyword to another silo"
                                            wire:change="assignKeywordToSilo('{{ $kw['id'] }}', $event.target.value)">
                                            <option value="">move…</option>
                                            @foreach ($this->siloOptions as $sid => $sname)
                                                @if ($sid !== $b['id'])
                                                    <option value="{{ $sid }}">{{ $sname }}</option>
                                                @endif
                                            @endforeach
                                            <option value="none">— unassign —</option>
                                        </select>
                                        <button type="button" class="tg-btn" title="Promote" wire:click="promote('{{ $kw['id'] }}')">▲</button>
                                        <button type="button" class="tg-btn" title="Demote" wire:click="demote('{{ $kw['id'] }}')">▼</button>
                                    </div>
                                @empty
                                    <div class="tg-row" style="color:#94a3b8">No keyword targets yet — run Discover keywords.</div>
                                @endforelse
                            </div>
                            @if ($b && $b['total'] > count($b['keywords']))
                                <div class="tg-more">
                                    <a href="{{ \App\Filament\Resources\KeywordResource::getUrl('index') }}" wire:navigate>+ {{ $b['total'] - count($b['keywords']) }} more targets →</a>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    {{-- Safety net: a §4 silo with keyword targets but no matching tree card (rare, pre-sync)
                         still surfaces its keywords rather than hiding them. --}}
                    @foreach ($board['silos'] as $silo)
                        @if (! in_array($silo['name'], $treeNames, true))
                            <div class="tg-card" wire:key="tg-orphan-{{ $silo['id'] }}" style="border-color:rgba(217,119,6,.4)">
                                <div class="tg-cardhead">
                                    <h3>{{ $silo['name'] }}</h3>
                                    <span class="tg-badge thin">no page yet</span>
                                    <span class="tg-split">{{ $silo['covered'] }} covered · {{ $silo['gaps'] }} gaps</span>
                                </div>
                                <div class="tg-rows">
                                    @foreach ($silo['keywords'] as $kw)
                                        <div class="tg-row" wire:key="tgo-{{ $kw['id'] }}">
                                            <span class="tg-q" title="{{ $kw['query'] }}">{{ $kw['query'] }}</span>
                                            <span class="tg-num">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                                            <select class="tg-sel" title="Move this keyword to another silo"
                                                wire:change="assignKeywordToSilo('{{ $kw['id'] }}', $event.target.value)">
                                                <option value="">move…</option>
                                                @foreach ($this->siloOptions as $sid => $sname)
                                                    @if ($sid !== $silo['id'])
                                                        <option value="{{ $sid }}">{{ $sname }}</option>
                                                    @endif
                                                @endforeach
                                                <option value="none">— unassign —</option>
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                <div class="g-empty">No structure yet — generate it above; each silo's pages and keyword targets show here together.</div>
            @endif

            @if ($this->demandReport !== [])
                <div class="tg-card" style="border-color:rgba(217,119,6,.4)">
                    <div class="tg-cardhead" style="background:rgba(217,119,6,.08)">
                        <h3>Demand without a service</h3>
                        <span class="tg-badge thin">{{ count($this->demandReport) }}</span>
                        <span class="tg-split">real search demand you don't yet offer a service for</span>
                    </div>
                    <div class="tg-rows">
                        @foreach ($this->demandReport as $finding)
                            <div class="tg-row" wire:key="dws-{{ $finding['cluster_id'] }}">
                                <span class="tg-q">{{ $finding['label'] ?: $finding['head_term'] }}</span>
                                <span class="tg-num">{{ $finding['volume'] !== null ? number_format((int) $finding['volume']).'/mo' : '—' }}</span>
                                <button type="button" class="tg-btn" title="Create a service linked into this silo" wire:click="createServiceFromDemand('{{ $finding['cluster_id'] }}')">+ Add service</button>
                                <button type="button" class="tg-btn" title="Dismiss this finding" wire:click="dismissDemand('{{ $finding['cluster_id'] }}')">Dismiss</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($board['unassigned_total'] > 0)
                <div class="tg-card">
                    <div class="tg-cardhead" style="display:flex;align-items:center;gap:8px">
                        <h3>Unassigned keywords</h3>
                        <span class="tg-badge thin">no silo</span>
                        <span class="tg-split">{{ $board['unassigned_total'] }} total</span>
                        <button type="button" class="g-btn" style="margin-left:auto" wire:click="rebucketKeywords"
                            title="Re-file these into silos by rule_set match (silos need rule_sets — they get them at generate).">
                            ⇄ Re-file into silos
                        </button>
                    </div>
                    <div class="tg-rows">
                        @foreach ($board['unassigned'] as $kw)
                            <div class="tg-row" wire:key="tgu-{{ $kw['id'] }}">
                                <span class="tg-q" title="{{ $kw['query'] }}">{{ $kw['query'] }}</span>
                                <span class="tg-num">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                                <span class="tg-cov {{ $kw['covered'] ? 'covered' : 'gap' }}">{{ $kw['covered'] ? 'covered' : 'gap' }}</span>
                                <select class="tg-sel" title="File this keyword into a silo"
                                    wire:change="assignKeywordToSilo('{{ $kw['id'] }}', $event.target.value)">
                                    <option value="">move to silo…</option>
                                    @foreach ($this->siloOptions as $sid => $sname)
                                        <option value="{{ $sid }}">{{ $sname }}</option>
                                    @endforeach
                                </select>
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
        @include('filament.gathering._next')
    </div>
</x-filament-panels::page>
