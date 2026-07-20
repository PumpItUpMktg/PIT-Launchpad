<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'This turns everything you told us into your website plan — the topic groups, the pages, and the search terms each page targets. Build the plan, review it, and approve. Extra ideas become blog posts later in Operate → Blog.'])

        <style>
            .tg-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(420px, 1fr)); gap:12px; }
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
            /* Keyword rows stack: the full query on its own line (always readable), metrics + controls below. */
            .tg-krow { display:flex; flex-direction:column; gap:5px; padding:8px 14px; border-bottom:1px solid rgba(148,163,184,.15); }
            .tg-krow:last-child { border-bottom:0; }
            .tg-kq { font-size:12.5px; line-height:1.3; word-break:break-word; }
            .tg-kmeta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
            .tg-kmeta .tg-num { min-width:34px; }
        </style>

        @if ($pruneMode && $started)
            {{-- ── Prune mode: fold / route / drop, then finalize (the mode, not a menu item) ── --}}
            <div class="g-row" style="justify-content:space-between">
                <div class="g-muted">Prune mode — route every candidate, then Finalize to lock the structure.</div>
                <button type="button" class="g-btn" wire:click="closePrune">← Back to targets</button>
            </div>

            @if ($finalized)
                <div class="g-card" style="border-color:rgba(22,163,74,.4); background:rgba(22,163,74,.06)">
                    <h3>Plan approved</h3>
                    <p class="g-hint">Your plan is locked in — this is the set of pages we'll build for your site. Extra blog ideas are queued for later.</p>
                    <button type="button" class="g-btn primary" wire:click="closePrune">Back to my plan</button>
                </div>
            @else
                @include('filament.pages.partials.prune-surface')
            @endif
        @else
            {{-- ── Generate ── --}}
            <div class="g-card">
                <h3>Your website plan</h3>
                @if (! $this->hasSeed)
                    <div class="g-empty">Nothing to plan yet — first tell us your trade on the Business step (or run the interview). We build the plan from that plus the services you list.</div>
                @elseif (! $this->hasSpokes)
                    <p class="g-hint">Ready to go ({{ $this->structureStatus === 'failed' ? 'last attempt didn\'t finish — try again below' : 'your trade + services are in' }}). We'll turn your services into a set of topic groups and pages, sized by what people search for.</p>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;margin-bottom:10px;cursor:pointer">
                        <input type="checkbox" wire:click="toggleBoundToServices" @checked($this->boundToServices)>
                        Only use the services I listed — don't add ones I don't offer
                    </label>
                    <div>
                        <button type="button" class="g-btn primary" wire:click="generate" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="generate">⚙ Build my plan</span>
                            <span wire:loading wire:target="generate">Building…</span>
                        </button>
                    </div>
                @else
                    <div class="g-row" style="justify-content:space-between">
                        <div>
                            <span class="g-seed {{ $this->blueprintConfirmed ? 'confirmed' : '' }}">{{ $this->blueprintConfirmed ? 'approved' : 'draft' }}</span>
                            <span class="g-muted" style="margin-left:6px">
                                {{ $this->blueprintConfirmed ? 'Your plan is approved — you only need to redo this if your business changes.' : 'Here\'s your draft plan — review it below, then approve to lock it in.' }}
                                @if ($this->blogTargetCount > 0)
                                    {{ $this->blogTargetCount }} blog idea(s) queued for Operate → Blog.
                                @endif
                            </span>
                        </div>
                        <div class="g-row" style="align-items:center">
                            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer" title="Only organize the services you actually offer — don't add adjacent ones. Rebuild to apply.">
                                <input type="checkbox" wire:click="toggleBoundToServices" @checked($this->boundToServices)>
                                My services only
                            </label>
                            <button type="button" class="g-btn" wire:click="generate" wire:loading.attr="disabled"
                                title="Refreshes search volumes and tidies the arrangement, but keeps your plan and any choices you've approved."
                                wire:confirm="Refresh your plan? Your approved choices are kept; search volumes and the arrangement update.">
                                <span wire:loading.remove wire:target="generate">↻ Refresh (keep my plan)</span>
                                <span wire:loading wire:target="generate">Running…</span>
                            </button>
                            <button type="button" class="g-btn" wire:click="rebuildStructure" wire:loading.attr="disabled"
                                title="Throws away the current plan and builds a brand-new one — use this only if you want to start over (e.g. after turning on 'My services only')."
                                wire:confirm="Start the plan over from scratch? This throws away the current plan (and its queued blog ideas) and builds a new one.">
                                <span wire:loading.remove wire:target="rebuildStructure">⟳ Start over</span>
                                <span wire:loading wire:target="rebuildStructure">Rebuilding…</span>
                            </button>
                            <button type="button" class="g-btn primary" wire:click="openPrune">✓ {{ $this->blueprintConfirmed ? 'Review again' : 'Review & approve' }}</button>
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
                {{-- Plain-language how-to: what this screen is + the 3 things to do, for a non-SEO owner. --}}
                <div class="g-card" style="background:rgba(59,130,246,.06); border-color:rgba(59,130,246,.3)">
                    <h3 style="margin-bottom:4px">Your website plan</h3>
                    <p class="g-hint" style="margin-bottom:8px">We grouped your services into <strong>topics</strong>. Each topic becomes a set of pages on your site, and each page targets the words people type into Google to find that service.</p>
                    <ol class="g-hint" style="margin:0; padding-left:18px; line-height:1.7">
                        <li>Look over the <strong>topic groups</strong> below — each one is a section of your website.</li>
                        <li>Click <strong>“Find search terms”</strong> to fill each topic with the words people actually search for.</li>
                        <li>If something's in the wrong group, use <strong>“Move”</strong> to shift it — then <strong>“Review &amp; approve”</strong> up top to lock the plan in.</li>
                    </ol>
                </div>

                <div class="g-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:4px 0 8px">
                    <div class="g-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">Topic groups — {{ count($this->tree) }}</div>
                    <button type="button" class="g-btn" wire:click="discoverKeywords"
                        wire:confirm="Find the search terms for each topic? This looks up what people search for and fills in your targets (may take a moment).">
                        🔎 Find search terms
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
                                    <span class="tg-badge {{ $b['viable'] ? 'ok' : 'thin' }}" title="{{ $b['viable'] ? 'Enough search terms to build strong pages' : 'Needs more search terms — click Find search terms' }}">{{ $b['viable'] ? 'ready' : 'needs more' }}</span>
                                @endif
                                <span class="tg-split">{{ $pageCount }} page(s) · {{ $sorted->count() }} topic(s)</span>
                            </div>

                            {{-- Pages (the structure the build produces) --}}
                            <div class="tg-subhead">Pages we'll build</div>
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
                                        @unless ($row->isPillar)
                                            <button type="button" class="tg-btn" title="Pull this out into its own topic group instead of a section here"
                                                wire:click="promoteToOwnTopic('{{ $row->id }}')"
                                                wire:confirm="Make “{{ $row->name }}” its own topic? It becomes a standalone section of your site with its own page.">↗ Own topic</button>
                                        @endunless
                                    </div>
                                @endforeach
                                @if ($sorted->count() > 8)
                                    <div class="tg-more">+ {{ $sorted->count() - 8 }} more — open “Review &amp; approve” to see everything</div>
                                @endif
                            </div>

                            {{-- Keyword targets (what discovery routes into this silo) --}}
                            <div class="tg-subhead">
                                Search terms we target
                                @if ($b)<span class="tg-split">{{ $b['covered'] }} have a page · {{ $b['gaps'] }} need one</span>@endif
                            </div>
                            @if ($b && $b['warning'] !== null)
                                <div class="tg-warn">{{ $b['warning'] }}</div>
                            @endif
                            <div class="tg-rows">
                                @forelse ($b['keywords'] ?? [] as $kw)
                                    <div class="tg-krow" wire:key="tgk-{{ $kw['id'] }}">
                                        <div class="tg-kq" title="{{ $kw['query'] }}">{{ $kw['query'] }}</div>
                                        <div class="tg-kmeta">
                                            <span class="tg-num" title="Monthly search volume">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                                            <span class="tg-num" title="How good an opportunity this term is (higher = better)">{{ $kw['opportunity'] !== null ? number_format($kw['opportunity'], 2) : '—' }}</span>
                                            <span class="tg-cov {{ $kw['covered'] ? 'covered' : 'gap' }}" title="{{ $kw['covered'] ? 'A page targets this term' : 'No page targets this term yet' }}">{{ $kw['covered'] ? 'has page' : 'needs page' }}</span>
                                            <span class="tg-pri" title="Priority (use ▲ ▼ to raise or lower)">{{ $kw['priority'] }}</span>
                                            <select class="tg-sel" title="Move this search term to another topic group"
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
                                    </div>
                                @empty
                                    <div class="tg-row" style="color:#94a3b8">No search terms yet — click “Find search terms” above.</div>
                                @endforelse
                            </div>
                            @if ($b && $b['total'] > count($b['keywords']))
                                <div class="tg-more">
                                    <a href="{{ \App\Filament\Resources\KeywordResource::getUrl('index') }}" wire:navigate>+ {{ $b['total'] - count($b['keywords']) }} more search terms →</a>
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
                                    <span class="tg-split">{{ $silo['covered'] }} have a page · {{ $silo['gaps'] }} need one</span>
                                </div>
                                <div class="tg-rows">
                                    @foreach ($silo['keywords'] as $kw)
                                        <div class="tg-krow" wire:key="tgo-{{ $kw['id'] }}">
                                            <div class="tg-kq" title="{{ $kw['query'] }}">{{ $kw['query'] }}</div>
                                            <div class="tg-kmeta">
                                                <span class="tg-num" title="Monthly search volume">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
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
                        <h3>Searches with no matching service</h3>
                        <span class="tg-badge thin">{{ count($this->demandReport) }}</span>
                        <span class="tg-split">people search for these, but you don't list the service yet</span>
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
                        <h3>Search terms not sorted yet</h3>
                        <span class="tg-badge thin">no topic</span>
                        <span class="tg-split">{{ $board['unassigned_total'] }} total</span>
                        <button type="button" class="g-btn" style="margin-left:auto" wire:click="rebucketKeywords"
                            title="Let the system sort these into the closest matching topic. Anything it can't place stays here for you to move by hand.">
                            ⇄ Auto-sort into topics
                        </button>
                    </div>
                    <div class="tg-rows">
                        @foreach ($board['unassigned'] as $kw)
                            <div class="tg-krow" wire:key="tgu-{{ $kw['id'] }}">
                                <div class="tg-kq" title="{{ $kw['query'] }}">{{ $kw['query'] }}</div>
                                <div class="tg-kmeta">
                                    <span class="tg-num" title="Monthly search volume">{{ $kw['volume'] !== null ? number_format((int) $kw['volume']) : '—' }}</span>
                                    <span class="tg-cov {{ $kw['covered'] ? 'covered' : 'gap' }}" title="{{ $kw['covered'] ? 'A page targets this term' : 'No page targets this term yet' }}">{{ $kw['covered'] ? 'has page' : 'needs page' }}</span>
                                    <select class="tg-sel" title="Add this search term to a topic group"
                                        wire:change="assignKeywordToSilo('{{ $kw['id'] }}', $event.target.value)">
                                        <option value="">add to a topic…</option>
                                        @foreach ($this->siloOptions as $sid => $sname)
                                            <option value="{{ $sid }}">{{ $sname }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="tg-btn" title="Promote" wire:click="promote('{{ $kw['id'] }}')">▲</button>
                                    <button type="button" class="tg-btn" title="Demote" wire:click="demote('{{ $kw['id'] }}')">▼</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="g-muted">
                Want the full detail? See the
                <a href="{{ \App\Filament\Resources\KeywordResource::getUrl('index') }}" wire:navigate>full list of search terms</a> or the
                <a href="{{ \App\Filament\Resources\SiloManagementResource::getUrl('index') }}" wire:navigate>topic list</a>.
                Ongoing blog ideas live in Operate → Blog.
            </div>
        @endif
        @include('filament.gathering._next')
    </div>
</x-filament-panels::page>
