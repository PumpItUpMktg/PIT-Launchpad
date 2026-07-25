<x-filament-panels::page>
    <style>
        .pl-wrap { display:flex; flex-direction:column; gap:14px; }
        .pl-head { display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .pl-sub { color:#64748b; font-size:13px; max-width:66ch; margin:4px 0 0; }
        .pl-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:4px 8px; background:transparent; }
        .pl-tiles { display:flex; gap:10px; flex-wrap:wrap; }
        .pl-tile { border:1px solid rgba(148,163,184,.35); border-radius:10px; padding:10px 16px; min-width:120px; }
        .pl-tile .n { font-size:21px; font-weight:700; font-variant-numeric:tabular-nums; }
        .pl-tile .l { font-size:11px; color:#94a3b8; }
        .pl-tile.bad { border-color:rgba(220,38,38,.5); } .pl-tile.bad .n { color:#dc2626; }
        .pl-tile.good { border-color:rgba(22,163,74,.5); } .pl-tile.good .n { color:#16a34a; }
        .pl-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:12px; }
        .pl-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; display:flex; flex-direction:column; overflow:hidden; }
        .pl-top { display:flex; align-items:flex-start; gap:10px; padding:13px 15px; background:rgba(148,163,184,.07); border-bottom:1px solid rgba(148,163,184,.2); flex-wrap:wrap; }
        .pl-top h3 { margin:0; font-size:15.5px; }
        .pl-nap { font-size:12px; color:#94a3b8; margin-top:2px; }
        .pl-chips { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; }
        .pl-chip { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.15); color:#64748b; white-space:nowrap; }
        .pl-chip.good { background:rgba(22,163,74,.12); color:#16a34a; }
        .pl-chip.warn { background:rgba(217,119,6,.13); color:#b45309; }
        .pl-chip.bad { background:rgba(220,38,38,.12); color:#dc2626; }
        .pl-chip a { color:inherit; text-decoration:none; }
        .pl-body { padding:12px 15px; display:flex; flex-direction:column; gap:10px; }
        .pl-stats { display:flex; gap:16px; flex-wrap:wrap; font-size:12.5px; color:#64748b; }
        .pl-stats b { font-variant-numeric:tabular-nums; }
        .pl-metrics { display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; padding:11px 15px; border-top:1px solid rgba(148,163,184,.15); }
        .pl-m .k { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
        .pl-m .v { font-size:17px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:1px; }
        .pl-m .v .up { color:#16a34a; } .pl-m .v .down { color:#dc2626; }
        .pl-m .sub { font-size:10.5px; color:#94a3b8; }
        .pl-m .pend { font-size:11.5px; color:#94a3b8; margin-top:3px; }
        .pl-towns { display:flex; gap:6px; flex-wrap:wrap; }
        .pl-town { font-size:11.5px; padding:2px 9px; border-radius:99px; border:1px solid rgba(148,163,184,.35); color:#64748b; }
        .pl-band { border-radius:9px; padding:9px 12px; font-size:12.5px; line-height:1.5; }
        .pl-band.overlap { border:1px solid rgba(220,38,38,.4); color:#dc2626; }
        .pl-band.advisory { border:1px solid rgba(217,119,6,.4); color:#b45309; }
        .pl-band strong { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
        .pl-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:15px; color:#94a3b8; font-size:13px; }
        .pl-link { font-size:12px; }
        .pl-foot { display:flex; align-items:center; gap:9px; flex-wrap:wrap; padding:10px 15px; border-top:1px solid rgba(148,163,184,.2); background:rgba(148,163,184,.04); }
        .pl-state { font-size:11.5px; color:#64748b; margin-right:auto; }
        .pl-state b { color:#334155; font-weight:600; }
        .pl-btn { font-size:12px; font-weight:600; padding:5px 12px; border-radius:7px; border:1px solid rgba(148,163,184,.5); background:transparent; color:#334155; cursor:pointer; }
        .pl-btn:hover { border-color:rgba(100,116,139,.8); }
        .pl-btn.primary { background:#2563eb; border-color:#2563eb; color:#fff; }
        .pl-btn.primary:hover { background:#1d4ed8; }
        .pl-btn.danger { border-color:rgba(220,38,38,.5); color:#b91c1c; }
        .pl-btn.danger:hover { border-color:#dc2626; background:rgba(220,38,38,.06); }
        a.pl-btn { text-decoration:none; display:inline-flex; align-items:center; }
        .pl-btn[disabled] { opacity:.5; cursor:not-allowed; }
        .pl-queuewarn { border:1px solid rgba(217,119,6,.5); background:rgba(217,119,6,.08); color:#b45309; border-radius:10px; padding:11px 15px; font-size:12.5px; line-height:1.55; }
        .pl-queuewarn code { background:rgba(217,119,6,.14); padding:1px 7px; border-radius:5px; font-size:11.5px; }
    </style>

    <div class="pl-wrap">
        @php $board = $this->board; $s = $board['summary']; @endphp

        <div class="pl-head">
            <div>
                <p class="pl-sub">Every location and the area it serves — the dispatch points the engine builds from. Overlap between locations is flagged per town (goal: zero). Soft rule, not a wall: a location should serve the county it sits in and that county's towns. <a href="{{ $this->serviceAreaUrl() }}" wire:navigate>Edit territory</a> · <a href="{{ $this->addLocationUrl() }}" wire:navigate>Add a location (GBP import)</a>.</p>
            </div>
            <select class="pl-select" wire:change="setSite($event.target.value)">
                @foreach ($this->siteOptions as $id => $label)
                    <option value="{{ $id }}" @selected($id === $this->siteId)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="pl-tiles">
            <div class="pl-tile"><div class="n">{{ $s['locations'] }}</div><div class="l">locations</div></div>
            <div class="pl-tile"><div class="n">{{ $s['towns_covered'] }}</div><div class="l">towns covered</div></div>
            <div class="pl-tile"><div class="n">{{ $s['towns_selected'] }}</div><div class="l">selected for pages</div></div>
            <div class="pl-tile {{ $s['overlaps'] > 0 ? 'bad' : 'good' }}"><div class="n">{{ $s['overlaps'] }}</div><div class="l">overlapping towns</div></div>
        </div>

        {{-- Stalled-worker banner: publishing is async (Repush queues a job the worker runs). If the
             worker is down, approved pages sit forever — so surface it here, with the drain escape hatch,
             instead of it looking like a broken button. --}}
        @php $q = $this->queueHealth; @endphp
        @if ($q['stalled'])
            <div class="pl-queuewarn">
                ⚠ The background worker looks stalled — <b>{{ $q['pending'] }}</b> job(s) queued{{ $q['oldest_minutes'] > 0 ? ' (oldest '.$q['oldest_minutes'].'m)' : '' }}{{ $q['failed'] > 0 ? ', '.$q['failed'].' failed' : '' }}.
                Approved pages won't publish until it drains. Fix the worker (Horizon / <code>queue:work</code>), or push this tenant's stuck pages now on the console:
                <code>php artisan launchpad:drain-publish "{{ $q['brand'] }}"</code>
            </div>
        @endif

        @if ($board['cards'] === [])
            <div class="pl-empty">No physical locations yet — import them on Setup → Business (bulk GBP) or add them in Settings → Locations.</div>
        @else
            <div class="pl-grid">
                @foreach ($board['cards'] as $card)
                    <div class="pl-card" wire:key="pl-{{ $card['id'] }}">
                        <div class="pl-top">
                            <div>
                                <h3>{{ $card['name'] }}</h3>
                                <div class="pl-nap">{{ $card['address'] ?: 'No address on file' }}{{ $card['phone'] ? ' · '.$card['phone'] : '' }}</div>
                            </div>
                            <div class="pl-chips">
                                <span class="pl-chip">{{ $card['storefront'] ? 'storefront' : 'service area' }}</span>
                                <span class="pl-chip {{ $card['located'] ? 'good' : 'warn' }}">{{ $card['located'] ? 'located' : 'not located' }}</span>
                                @if ($card['gbp_linked'])
                                    <span class="pl-chip good">@if ($card['gbp_url'])<a href="{{ $card['gbp_url'] }}" target="_blank" rel="noopener">GBP ↗</a>@else GBP linked @endif</span>
                                @else
                                    <span class="pl-chip warn">no GBP</span>
                                @endif
                                @if ($card['home_resolved'])
                                    <span class="pl-chip {{ $card['serves_home_county'] ? 'good' : 'warn' }}">{{ $card['serves_home_county'] ? 'serves home county' : 'home county unserved' }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="pl-body">
                            <div class="pl-stats">
                                <span><b>{{ $card['counties_served'] }}</b> {{ \Illuminate\Support\Str::plural('county', $card['counties_served']) }} served</span>
                                <span><b>{{ $card['towns_covered'] }}</b> towns covered</span>
                                <span><b>{{ $card['towns_selected'] }}</b> selected for pages</span>
                                @if ($card['home_county_towns'] !== null)
                                    <span><b>{{ $card['home_county_towns'] }}</b> in its home county</span>
                                @endif
                            </div>

                            @if ($card['town_sample'] !== [])
                                <div class="pl-towns">
                                    @foreach ($card['town_sample'] as $town)
                                        <span class="pl-town">{{ $town }}</span>
                                    @endforeach
                                    @if ($card['towns_covered'] > count($card['town_sample']))
                                        <span class="pl-town" style="border-style:dashed">+ {{ $card['towns_covered'] - count($card['town_sample']) }} more</span>
                                    @endif
                                </div>
                            @endif

                            @if ($card['overlaps'] !== [])
                                <div class="pl-band overlap">
                                    <strong>Overlap — {{ count($card['overlaps']) }} town(s) also served by another location</strong>
                                    @foreach ($card['overlaps'] as $overlap)
                                        {{ $overlap['town'] }} (also {{ implode(', ', $overlap['with']) }})@if (! $loop->last) · @endif
                                    @endforeach
                                    <div style="margin-top:4px"><a class="pl-link" href="{{ $this->serviceAreaUrl() }}" wire:navigate>Resolve in the territory workspace →</a></div>
                                </div>
                            @endif

                            @foreach ($card['advisories'] as $advisory)
                                <div class="pl-band advisory">{{ $advisory }}</div>
                            @endforeach
                        </div>

                        @php $pg = $card['page']; @endphp

                        {{-- Tracking block — the SAME Position / GSC / GA4 metrics every page card shows,
                             with honest pending reasons ("No target keyword — brand page", "Connect …"). --}}
                        @if ($pg['metrics'])
                            @php $m = $pg['metrics']; @endphp
                            <div class="pl-metrics">
                                <div class="pl-m">
                                    <div class="k">Position</div>
                                    @if ($m['position']['rank'] !== null)
                                        <div class="v">#{{ $m['position']['rank'] }}
                                            @if (($m['position']['delta'] ?? null) > 0)<span class="up">▲{{ $m['position']['delta'] }}</span>
                                            @elseif (($m['position']['delta'] ?? null) < 0)<span class="down">▼{{ abs($m['position']['delta']) }}</span>@endif
                                        </div>
                                    @else
                                        <div class="pend">{{ $m['position']['pending'] ?? 'Pending' }}</div>
                                    @endif
                                </div>
                                <div class="pl-m">
                                    <div class="k">GSC · 28d</div>
                                    @if ($m['gsc']['impressions'] !== null)
                                        <div class="v">{{ number_format($m['gsc']['impressions']) }}</div>
                                        <div class="sub">impr · {{ number_format((int) $m['gsc']['clicks']) }} clicks</div>
                                    @else
                                        <div class="pend">{{ $m['gsc']['pending'] }}</div>
                                    @endif
                                </div>
                                <div class="pl-m">
                                    <div class="k">GA4 sessions</div>
                                    @if ($m['traffic']['sessions'] !== null)
                                        <div class="v">{{ number_format($m['traffic']['sessions']) }}</div>
                                        <div class="sub">28d</div>
                                    @else
                                        <div class="pend">{{ $m['traffic']['pending'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div class="pl-foot">
                            <span class="pl-state">Page: <b>{{ $pg['label'] }}</b></span>

                            @if ($pg['content_id'] ?? null)
                                {{-- The standard page actions — the same set as every other board. Plain
                                     single-label buttons (Livewire disables them mid-flight); no loading
                                     span, which is what left "Repush Pushing…" showing at rest before. --}}
                                <a class="pl-btn" href="{{ \App\Filament\Pages\ProofEditor::getUrl(['content' => $pg['content_id']]) }}" wire:navigate>Review</a>
                                <button class="pl-btn primary" wire:click="repush('{{ $pg['content_id'] }}')" wire:loading.attr="disabled" wire:target="repush('{{ $pg['content_id'] }}')">Repush</button>
                                <button class="pl-btn" wire:click="regenerate('{{ $pg['content_id'] }}')" wire:loading.attr="disabled" wire:target="regenerate('{{ $pg['content_id'] }}')">Regenerate</button>
                                <button class="pl-btn danger" wire:click="takeDown('{{ $pg['content_id'] }}')"
                                    wire:confirm="Remove this location page from WordPress? It stays in your plan and can be republished on the same URL."
                                    wire:loading.attr="disabled" wire:target="takeDown('{{ $pg['content_id'] }}')">Take down</button>
                            @else
                                {{-- No landing page yet — generate it (find-or-creates the page, then drafts). --}}
                                <button class="pl-btn primary" wire:click="generatePage('{{ $card['id'] }}')" @disabled(! $pg['can_generate']) wire:loading.attr="disabled" wire:target="generatePage('{{ $card['id'] }}')">Generate</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
