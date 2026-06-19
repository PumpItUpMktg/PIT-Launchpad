<x-filament-panels::page>
    {{-- Self-contained styling (namespaced .lp-*) — the custom page's classes aren't in
         Filament's compiled app.css, and the deploy doesn't build a custom theme, so the
         design ships inline here. Won't collide with Filament's fi-* classes. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=Inter:wght@400;500;600&family=Spline+Sans+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        .lp-wrap { --teal-major:#0A4F4F; --teal-large:#0E6B6B; --teal-medium:#4E9A98; --teal-small:#A6CFCD; --ungrouped:#C3CCD6;
            --ink:#13343b; --muted:#5b7178; --line:#e3eaec; --surface:#ffffff; --surface-2:#f5f8f8; --accent:#0E6B6B;
            font-family:'Inter',ui-sans-serif,system-ui,sans-serif; color:var(--ink); display:flex; flex-direction:column; gap:16px; }
        .dark .lp-wrap { --ink:#e6eef0; --muted:#9fb3b8; --line:#23373c; --surface:#0f2226; --surface-2:#13292e; }
        .lp-wrap *{ box-sizing:border-box; }
        .lp-card { background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:20px; box-shadow:0 1px 2px rgba(13,52,52,.04); }
        .lp-h { font-family:'Archivo',sans-serif; font-weight:700; }
        .lp-muted { color:var(--muted); font-size:13px; }
        .lp-label { display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; }
        .lp-select, .lp-input { width:100%; max-width:340px; padding:9px 12px; border:1px solid var(--line); border-radius:10px;
            background:var(--surface); color:var(--ink); font-size:14px; font-family:inherit; }
        .lp-input { max-width:none; }
        .lp-warn { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; border-radius:12px; padding:12px 16px; font-size:13px; }

        /* Hero */
        .lp-hero-row { display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:18px; }
        .lp-nums { display:flex; gap:34px; }
        .lp-num { font-family:'Archivo',sans-serif; font-weight:700; font-size:30px; line-height:1; }
        .lp-num.alt { color:var(--teal-large); }
        .lp-num-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-top:6px; }
        .lp-badge { border-radius:999px; padding:5px 12px; font-size:12px; font-weight:600; }
        .lp-badge.ok { background:#e7f6ee; color:#1b7a47; }
        .lp-badge.warn { background:#fdf3e2; color:#9a6a16; }
        .lp-bar { display:flex; height:10px; width:100%; overflow:hidden; border-radius:999px; background:var(--surface-2); margin-top:16px; }
        .lp-bar > span { display:block; height:100%; }
        .lp-legend { display:flex; flex-wrap:wrap; gap:6px 16px; margin-top:10px; font-size:12px; color:var(--muted); }
        .lp-legend span { display:inline-flex; align-items:center; gap:6px; }
        .lp-sw { width:11px; height:11px; border-radius:3px; display:inline-block; }

        /* Tabs */
        .lp-tabs { display:flex; flex-wrap:wrap; gap:8px; }
        .lp-tab { display:inline-flex; align-items:center; gap:8px; padding:9px 13px; border-radius:11px; border:1px solid var(--line);
            background:var(--surface-2); cursor:pointer; font-size:14px; color:var(--ink); }
        .lp-tab:hover { background:var(--surface); }
        .lp-tab.active { background:var(--surface); border-color:var(--accent); box-shadow:0 0 0 1px var(--accent) inset; }
        .lp-tab.add { border-style:dashed; color:var(--muted); }
        .lp-tab.add.on { border-color:var(--accent); color:var(--accent); }
        .lp-dot { width:11px; height:11px; border-radius:999px; flex:0 0 auto; }
        .lp-tab-name { font-weight:600; }
        .lp-tab-count { font-size:12px; color:var(--muted); }
        .lp-tab-badge { background:var(--teal-major); color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; font-weight:700; }

        /* Status pills */
        .lp-status { border-radius:999px; padding:2px 10px; font-size:12px; font-weight:600; white-space:nowrap; }
        .lp-status.ok { background:#e7f6ee; color:#1b7a47; }
        .lp-status.bad { background:#fde8e8; color:#a23b3b; }
        .lp-status.wait { background:var(--surface-2); color:var(--muted); }

        /* Location panel */
        .lp-panel { display:flex; flex-direction:column; gap:18px; }
        .lp-loc-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
        .lp-loc-name { font-family:'Archivo',sans-serif; font-weight:600; font-size:16px; }
        .lp-loc-addr { font-size:13px; color:var(--muted); margin-top:2px; }
        .lp-loc-coords { font-size:11px; color:var(--muted); font-family:'Spline Sans Mono',monospace; margin-top:2px; }
        .lp-rule { border-top:1px solid var(--line); padding-top:14px; }
        .lp-seclbl { font-size:12px; font-weight:600; color:var(--muted); margin-bottom:7px; }

        /* Chips (counties) */
        .lp-chips { display:flex; flex-wrap:wrap; gap:7px; }
        .lp-chip { border-radius:999px; padding:5px 11px; font-size:12px; border:1px solid var(--line); background:var(--surface);
            color:var(--ink); cursor:pointer; }
        .lp-chip:hover { background:var(--surface-2); }
        .lp-chip.on { background:var(--accent); border-color:var(--accent); color:#fff; }

        /* Compact searchable county multi-select */
        [x-cloak] { display:none !important; }
        .lp-combo { position:relative; max-width:420px; }
        .lp-combo-box { display:flex; flex-wrap:wrap; gap:6px; align-items:center; min-height:40px; padding:6px 10px;
            border:1px solid var(--line); border-radius:10px; background:var(--surface); cursor:pointer; }
        .lp-tag { display:inline-flex; align-items:center; gap:6px; background:var(--accent); color:#fff; border-radius:999px;
            padding:3px 6px 3px 10px; font-size:12px; }
        .lp-tag-home { background:rgba(255,255,255,.25); border-radius:999px; padding:0 6px; font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
        .lp-tag-x { background:none; border:0; color:#fff; cursor:pointer; font-size:14px; line-height:1; padding:0 2px; }
        .lp-combo-menu { position:absolute; z-index:30; top:calc(100% + 4px); left:0; right:0; background:var(--surface);
            border:1px solid var(--line); border-radius:10px; box-shadow:0 8px 24px rgba(13,52,52,.14); padding:8px; }
        .lp-combo-list { max-height:220px; overflow:auto; margin-top:8px; display:flex; flex-direction:column; gap:2px; }
        .lp-combo-opt { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:8px; font-size:13px; cursor:pointer; }
        .lp-combo-opt:hover { background:var(--surface-2); }

        /* Locstat + minibar */
        .lp-locstat { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .lp-locstat .n { font-weight:600; }
        .lp-pill { background:var(--teal-major); color:#fff; border-radius:999px; padding:2px 10px; font-size:12px; font-weight:700; }
        .lp-mini { display:flex; height:8px; flex:1; min-width:120px; overflow:hidden; border-radius:999px; background:var(--surface-2); }
        .lp-mini > span { display:block; height:100%; }

        /* Tier groups */
        .lp-tgroup { border:1px solid var(--line); border-radius:11px; overflow:hidden; }
        .lp-tgroup-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 12px; }
        .lp-tgroup-title { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; background:none; border:0; cursor:pointer; color:var(--ink); }
        .lp-tgroup-frac { font-size:12px; color:var(--muted); font-weight:500; }
        .lp-tgroup-actions { display:flex; gap:4px; font-size:12px; }
        .lp-link { background:none; border:0; cursor:pointer; border-radius:6px; padding:2px 8px; color:var(--accent); }
        .lp-link.dim { color:var(--muted); }
        .lp-link:hover { background:var(--surface-2); }
        .lp-towns { display:flex; flex-wrap:wrap; gap:7px; padding:12px; border-top:1px solid var(--line); }

        /* Town checkbox chips */
        .lp-town { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:5px 11px; font-size:12px;
            border:1px solid var(--line); background:var(--surface); color:var(--ink); cursor:pointer; }
        .lp-town:hover { background:var(--surface-2); }
        .lp-town.on { background:var(--accent); border-color:var(--accent); color:#fff; }
        .lp-town-pop { opacity:.65; font-family:'Spline Sans Mono',monospace; font-size:11px; }

        /* Buttons / results / map / bottom bar */
        .lp-btn { display:inline-flex; align-items:center; gap:6px; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600;
            background:var(--accent); color:#fff; border:0; cursor:pointer; }
        .lp-btn:hover { filter:brightness(1.06); }
        .lp-btn.ghost { background:var(--surface-2); color:var(--ink); border:1px solid var(--line); }
        .lp-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
        .lp-result { display:block; width:100%; text-align:left; border:1px solid var(--line); background:var(--surface-2); border-radius:10px;
            padding:8px 12px; font-size:13px; cursor:pointer; }
        .lp-result:hover { background:var(--surface); }
        .lp-map { height:380px; width:100%; border-radius:12px; background:#e5e7eb; }
        .lp-bottom { position:sticky; bottom:8px; display:flex; align-items:center; justify-content:space-between; gap:12px;
            background:var(--teal-major); color:#fff; border-radius:14px; padding:13px 18px; font-size:14px; box-shadow:0 6px 18px rgba(10,79,79,.25); }
        .lp-bottom b { font-family:'Archivo',sans-serif; }
        .lp-empty { text-align:center; color:var(--muted); padding:28px; }
        .lp-add-stack { display:flex; flex-direction:column; gap:12px; }
        .lp-seg { display:inline-flex; gap:6px; }
        .lp-seg button { border:0; background:none; cursor:pointer; border-radius:8px; padding:5px 12px; font-size:13px; color:var(--muted); }
        .lp-seg button.on { background:var(--accent); color:#fff; }
    </style>

    @php
        $tierMeta = [
            'major' => ['label' => 'Major', 'color' => '#0A4F4F'],
            'large' => ['label' => 'Large', 'color' => '#0E6B6B'],
            'medium' => ['label' => 'Medium', 'color' => '#4E9A98'],
            'small' => ['label' => 'Small', 'color' => '#A6CFCD'],
            'ungrouped' => ['label' => 'Ungrouped', 'color' => '#C3CCD6'],
        ];
    @endphp

    <div class="lp-wrap">
        <div class="lp-card">
            <p class="lp-muted" style="margin:0 0 14px">Tell us where each location is and which counties you serve — then pick the towns you want location pages for.</p>
            <label>
                <span class="lp-label">Site</span>
                <select wire:model.live="siteId" class="lp-select">
                    <option value="">Select a site…</option>
                    @foreach ($this->siteOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($this->geocoderWarning)
            <div class="lp-warn">⚠ {{ $this->geocoderWarning }}</div>
        @endif

        @if ($siteId)
            @php
                $locations = $this->locations;
                $colors = $this->colors;
                $locating = $locations->contains(fn ($l) => $l->lat === null && ! $l->geocode_failed);
                $vm = $this->panels;
                $totals = $vm['totals'];
                $tierTotals = $totals['tiers'] ?? [];
                $activeLoc = $locations->firstWhere('id', $activeTab) ?? $locations->first();
                $activePanel = $activeLoc ? ($vm['panels'][$activeLoc->id] ?? null) : null;
                $overlap = $totals['overlap'] ?? 0;
            @endphp

            {{-- Totals hero --}}
            <div class="lp-card">
                <div class="lp-hero-row">
                    <div class="lp-nums">
                        <div>
                            <div class="lp-num">{{ $totals['covered'] }}</div>
                            <div class="lp-num-lbl">towns covered</div>
                        </div>
                        <div>
                            <div class="lp-num alt">{{ $totals['selected'] }}</div>
                            <div class="lp-num-lbl">selected for pages</div>
                        </div>
                    </div>
                    <span class="lp-badge {{ $overlap === 0 ? 'ok' : 'warn' }}">{{ $overlap === 0 ? 'no overlap' : $overlap.' overlapping' }}</span>
                </div>

                @if ($totals['covered'] > 0)
                    <div class="lp-bar">
                        @foreach ($tierMeta as $key => $meta)
                            @php $n = $tierTotals[$key] ?? 0; $pct = $totals['covered'] > 0 ? ($n / $totals['covered']) * 100 : 0; @endphp
                            @if ($n > 0)
                                <span style="width: {{ $pct }}%; background: {{ $meta['color'] }}" title="{{ $meta['label'] }}: {{ $n }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="lp-legend">
                        @foreach ($tierMeta as $key => $meta)
                            <span><span class="lp-sw" style="background: {{ $meta['color'] }}"></span>{{ $meta['label'] }} {{ $tierTotals[$key] ?? 0 }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Location tabs --}}
            <div class="lp-tabs" @if ($locating) wire:poll.3s @endif>
                @foreach ($locations as $location)
                    @php
                        $isActive = $activeLoc && $location->id === $activeLoc->id && ! $adding;
                        $color = $colors[$location->id] ?? '#2563eb';
                        $p = $vm['panels'][$location->id] ?? ['town_count' => 0, 'selected_count' => 0];
                    @endphp
                    <button type="button" wire:click="$set('activeTab', '{{ $location->id }}')" class="lp-tab {{ $isActive ? 'active' : '' }}">
                        <span class="lp-dot" style="background: {{ $color }}"></span>
                        <span class="lp-tab-name">{{ $location->name }}</span>
                        <span class="lp-tab-count">{{ $p['town_count'] }}</span>
                        @if ($p['selected_count'] > 0)
                            <span class="lp-tab-badge">{{ $p['selected_count'] }}</span>
                        @endif
                    </button>
                @endforeach
                <button type="button" wire:click="startAdd" class="lp-tab add {{ $adding ? 'on' : '' }}">＋ Add location</button>
            </div>

            {{-- Add-location panel --}}
            @if ($adding)
                <div class="lp-card lp-add-stack">
                    @if ($this->placesEnabled)
                        <div class="lp-seg">
                            <button type="button" wire:click="$set('addSource', 'places')" class="{{ $addSource === 'places' ? 'on' : '' }}">From Google</button>
                            <button type="button" wire:click="$set('addSource', 'manual')" class="{{ $addSource === 'manual' ? 'on' : '' }}">Enter manually</button>
                        </div>
                    @endif

                    @if ($addSource === 'places' && $this->placesEnabled)
                        <input type="text" wire:model="addQuery" wire:keydown.enter="searchPlaces" placeholder="business name or address" class="lp-input" />
                        <div class="lp-row"><button type="button" wire:click="searchPlaces" class="lp-btn ghost">Search</button></div>
                        @foreach ($placeResults as $r)
                            <button type="button" wire:click="addFromPlace('{{ $r['place_id'] }}')" class="lp-result">
                                <strong>{{ $r['name'] }}</strong><br><span class="lp-muted">{{ $r['address'] }}</span>
                            </button>
                        @endforeach
                    @else
                        <input type="text" wire:model="addName" placeholder="Location name (e.g. Montclair)" class="lp-input" />
                        <input type="text" wire:model="addAddress" placeholder="Where you are (address)" class="lp-input" />
                    @endif

                    <p class="lp-muted" style="margin:0">We’ll locate it and pre-tick its home county — adjust the counties you serve on the tab.</p>

                    <div class="lp-row">
                        @if ($addSource !== 'places' || ! $this->placesEnabled)
                            <button type="button" wire:click="addManual" class="lp-btn">Add location</button>
                        @endif
                        <button type="button" wire:click="cancelAdd" class="lp-btn ghost">Cancel</button>
                    </div>
                </div>
            @elseif ($activeLoc)
                @php
                    $located = $activeLoc->lat !== null && $activeLoc->lng !== null;
                    // countyOptions() self-heals the home county (may seed county_geoids) on the
                    // same instance — read the selection AFTER it so the combo seeds correctly.
                    $countyOptions = $located ? $this->countyOptions($activeLoc) : [];
                    $selectedCounties = is_array($activeLoc->county_geoids) ? array_values($activeLoc->county_geoids) : [];
                    $color = $colors[$activeLoc->id] ?? '#2563eb';
                @endphp
                <div class="lp-card lp-panel">
                    {{-- Located header --}}
                    <div class="lp-loc-head">
                        <div style="display:flex; gap:10px; align-items:flex-start">
                            <span class="lp-dot" style="background: {{ $color }}; margin-top:4px"></span>
                            <div>
                                <div class="lp-loc-name">{{ $activeLoc->name }}</div>
                                <div class="lp-loc-addr">{{ $activeLoc->address ?: 'No address on file' }}</div>
                                @if ($located)
                                    <div class="lp-loc-coords">✓ Located · {{ number_format((float) $activeLoc->lat, 3) }}, {{ number_format((float) $activeLoc->lng, 3) }}</div>
                                @endif
                            </div>
                        </div>
                        @php
                            $statusClass = $located ? 'ok' : ($activeLoc->geocode_failed ? 'bad' : 'wait');
                            $statusText = $located ? '● Located' : ($activeLoc->geocode_failed ? 'Couldn’t locate' : 'locating…');
                        @endphp
                        <span class="lp-status {{ $statusClass }}">{{ $statusText }}</span>
                    </div>

                    {{-- Counties served — compact searchable multi-select (sends the whole array,
                         so adds accumulate natively; home is the initial seed, never a floor) --}}
                    @if ($located)
                        <div>
                            <div class="lp-seclbl">Counties you serve</div>
                            @if ($countyOptions === [])
                                <div class="lp-muted">No counties found for this state.</div>
                            @else
                                <div class="lp-combo" wire:key="combo-{{ $activeLoc->id }}"
                                    x-data="{
                                        open: false, q: '',
                                        sel: @js($selectedCounties),
                                        home: @js((string) $activeLoc->home_county_geoid),
                                        options: @js($countyOptions),
                                        nameOf(g) { const o = this.options.find(o => o.geo_id === g); return o ? o.name : g; },
                                        toggle(g) { this.sel = this.sel.includes(g) ? this.sel.filter(x => x !== g) : [...this.sel, g]; $wire.setCounties(@js($activeLoc->id), this.sel); },
                                        filtered() { const q = this.q.toLowerCase(); return this.options.filter(o => o.name.toLowerCase().includes(q)); }
                                    }"
                                    x-on:click.outside="open = false">
                                    <div class="lp-combo-box" x-on:click="open = ! open">
                                        <template x-for="g in sel" :key="g">
                                            <span class="lp-tag">
                                                <span x-text="nameOf(g)"></span>
                                                <template x-if="g === home"><span class="lp-tag-home">home</span></template>
                                                <button type="button" class="lp-tag-x" x-on:click.stop="toggle(g)">×</button>
                                            </span>
                                        </template>
                                        <span x-show="sel.length === 0" class="lp-muted">Select counties…</span>
                                    </div>
                                    <div class="lp-combo-menu" x-show="open" x-cloak>
                                        <input type="text" x-model="q" placeholder="Search counties…" class="lp-input" x-on:click.stop>
                                        <div class="lp-combo-list">
                                            <template x-for="o in filtered()" :key="o.geo_id">
                                                <label class="lp-combo-opt" x-on:click.stop>
                                                    <input type="checkbox" :checked="sel.includes(o.geo_id)" x-on:change="toggle(o.geo_id)">
                                                    <span x-text="o.name"></span>
                                                    <template x-if="o.geo_id === home"><span class="lp-tag-home" style="background:var(--surface-2); color:var(--muted)">home</span></template>
                                                </label>
                                            </template>
                                            <div x-show="filtered().length === 0" class="lp-muted" style="padding:8px">No match.</div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- locstat + minibar --}}
                    @if ($activePanel && $activePanel['town_count'] > 0)
                        @php $pt = $activePanel['tiers']; @endphp
                        <div class="lp-locstat lp-rule">
                            <span class="n">{{ $activePanel['town_count'] }} towns</span>
                            <span class="lp-pill">{{ $activePanel['selected_count'] }} selected</span>
                            <div class="lp-mini">
                                @foreach ($tierMeta as $key => $meta)
                                    @php $n = $pt[$key] ?? 0; $pct = $activePanel['town_count'] > 0 ? ($n / $activePanel['town_count']) * 100 : 0; @endphp
                                    @if ($n > 0)
                                        <span style="width: {{ $pct }}%; background: {{ $meta['color'] }}"></span>
                                    @endif
                                @endforeach
                            </div>
                        </div>

                        {{-- Town groups by tier --}}
                        <div style="display:flex; flex-direction:column; gap:9px">
                            @foreach ($tierMeta as $key => $meta)
                                @php $towns = $activePanel['groups'][$key] ?? []; @endphp
                                @if (count($towns) > 0)
                                    @php $selInTier = collect($towns)->where('page_selected', true)->count(); @endphp
                                    <div x-data="{ open: true }" class="lp-tgroup" wire:key="lp-tgroup-{{ $activeLoc->id }}-{{ $key }}">
                                        <div class="lp-tgroup-head">
                                            <button type="button" x-on:click="open = ! open" class="lp-tgroup-title">
                                                <span class="lp-sw" style="background: {{ $meta['color'] }}"></span>
                                                {{ $meta['label'] }}
                                                <span class="lp-tgroup-frac">{{ $selInTier }} / {{ count($towns) }}</span>
                                            </button>
                                            <div class="lp-tgroup-actions">
                                                <button type="button" wire:click="selectTier('{{ $activeLoc->id }}', '{{ $key }}', true)" class="lp-link">Select all</button>
                                                <button type="button" wire:click="selectTier('{{ $activeLoc->id }}', '{{ $key }}', false)" class="lp-link dim">Clear</button>
                                            </div>
                                        </div>
                                        <div class="lp-towns" x-show="open">
                                            @foreach ($towns as $town)
                                                @php $pop = $town['population'] !== null ? number_format($town['population']) : '—'; @endphp
                                                <button type="button" wire:key="lp-town-{{ $town['geo_id'] }}" wire:click="togglePageSelection('{{ $town['geo_id'] }}')" class="lp-town {{ $town['page_selected'] ? 'on' : '' }}">
                                                    {{ $town['page_selected'] ? '✓' : '+' }} {{ $town['name'] }}@if ($town['manual']) 🚩 @endif
                                                    <span class="lp-town-pop">{{ $pop }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @elseif ($located)
                        <div class="lp-rule lp-muted">Tick a county above to enumerate its towns.</div>
                    @endif

                    {{-- Add a town beyond the served counties --}}
                    @if ($located)
                        <div class="lp-rule">
                            <div class="lp-seclbl">Add a town (beyond the served counties)</div>
                            <div class="lp-row">
                                <input type="text" wire:model="townQuery.{{ $activeLoc->id }}" wire:keydown.enter="searchTowns('{{ $activeLoc->id }}')" placeholder="town name" class="lp-input" style="max-width:260px" />
                                <button type="button" wire:click="searchTowns('{{ $activeLoc->id }}')" class="lp-btn ghost">Search</button>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px; margin-top:8px">
                                @foreach ($townResults[$activeLoc->id] ?? [] as $res)
                                    <button type="button" wire:click="addTown('{{ $activeLoc->id }}', '{{ $res['geo_id'] }}')" class="lp-result">+ {{ $res['name'] }}@if ($res['state']) , {{ $res['state'] }}@endif</button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Failed-geocode fallback --}}
                    @if ($activeLoc->geocode_failed)
                        <div class="lp-rule">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px">
                                <span class="lp-muted">Couldn’t locate this address automatically.</span>
                                <button type="button" wire:click="retryGeocode('{{ $activeLoc->id }}')" class="lp-btn ghost">↻ Retry locating</button>
                            </div>
                            <div class="lp-row" style="margin-top:8px">
                                <label><span class="lp-seclbl">Latitude</span><input type="text" wire:model="manualLat.{{ $activeLoc->id }}" class="lp-input" style="max-width:130px" /></label>
                                <label><span class="lp-seclbl">Longitude</span><input type="text" wire:model="manualLng.{{ $activeLoc->id }}" class="lp-input" style="max-width:130px" /></label>
                                <button type="button" wire:click="saveManualPoint('{{ $activeLoc->id }}')" class="lp-btn ghost">Set the spot</button>
                            </div>
                        </div>
                    @endif
                </div>
            @elseif ($locations->isEmpty())
                <div class="lp-card lp-empty">No locations yet — add the first one.</div>
            @endif

            {{-- Shared coverage map (pins per base + flagged directed towns) --}}
            @if (! $locations->isEmpty())
                <div class="lp-card" style="padding:8px">
                    <div wire:ignore
                        x-data="coverageMap(@js($this->mapData), @js($this->manualMarkers), @js($this->countyPolygons))"
                        x-init="init()"
                        x-on:locations-updated.window="render($event.detail.data ?? [], $event.detail.manual ?? [], $event.detail.polygons ?? [])">
                        <div x-ref="map" class="lp-map"></div>
                    </div>
                </div>

                {{-- Bottom bar: live selected · covered (persisted rows — counts agree with the hero) --}}
                <div class="lp-bottom">
                    <span><b>{{ $totals['selected'] }}</b> selected · <b>{{ $totals['covered'] }}</b> covered</span>
                    <button type="button" wire:click="compute" class="lp-btn ghost" style="background:rgba(255,255,255,.16); color:#fff; border:0">Update service area</button>
                </div>
            @endif
        @endif
    </div>

    {{-- Leaflet (OSM/CARTO tiles, no API key) --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script>
        // Defined as a plain global at parse time (NOT via alpine:init) so x-data can never
        // evaluate `coverageMap` before it exists — a throw in x-data would halt Alpine and,
        // with it, ALL Livewire interactivity. Every Leaflet touch is guarded so a failure
        // degrades to "no map", never a thrown init.
        window.coverageMap = (initial, initialManual, initialPolygons) => ({
                map: null,
                group: null,
                init() {
                    try {
                        this.ensureLeaflet(() => {
                            try {
                                const el = this.$refs.map;
                                if (el._lpMap) {
                                    this.map = el._lpMap;
                                } else {
                                    this.map = L.map(el, { scrollWheelZoom: false }).setView([40.3, -74.6], 8);
                                    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
                                        attribution: '© OpenStreetMap, © CARTO', maxZoom: 19,
                                    }).addTo(this.map);
                                    el._lpMap = this.map;
                                }
                                this.render(initial, initialManual, initialPolygons);
                            } catch (e) { console.error('coverage map init', e); }
                        });
                    } catch (e) { console.error('coverage map', e); }
                },
                ensureLeaflet(cb) {
                    if (window.L) return cb();
                    const existing = document.getElementById('lp-leaflet-js');
                    if (existing) { existing.addEventListener('load', cb); return; }
                    const s = document.createElement('script');
                    s.id = 'lp-leaflet-js';
                    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    s.onload = cb;
                    s.onerror = () => console.error('Leaflet failed to load');
                    document.head.appendChild(s);
                },
                render(data, manual, polygons) {
                    if (!this.map || !window.L) return;
                    if (this.group) this.map.removeLayer(this.group);
                    this.group = L.layerGroup().addTo(this.map);
                    const pts = [];
                    (polygons || []).forEach((c) => {
                        (c.rings || []).forEach((ring) => {
                            if (!ring || !ring.length) return;
                            const latlngs = ring.map((p) => [p.lat, p.lng]);
                            L.polygon(latlngs, { color: '#0E6B6B', weight: 2, fillColor: '#0E6B6B', fillOpacity: 0.07 })
                                .bindTooltip((c.name ? c.name + ' County' : 'County'), { permanent: false }).addTo(this.group);
                            latlngs.forEach((ll) => pts.push(ll));
                        });
                    });
                    (data || []).forEach((d) => {
                        if (d.lat == null || d.lng == null) return;
                        L.circleMarker([d.lat, d.lng], { radius: 6, color: d.color, fillColor: d.color, fillOpacity: 1 })
                            .bindTooltip(d.name, { permanent: false }).addTo(this.group);
                        pts.push([d.lat, d.lng]);
                    });
                    (manual || []).forEach((d) => {
                        if (d.lat == null || d.lng == null) return;
                        L.marker([d.lat, d.lng], {
                            icon: L.divIcon({ html: '🚩', className: 'lp-flag', iconSize: [18, 18], iconAnchor: [4, 16] }),
                        }).bindTooltip(d.name + ' (added)', { permanent: false }).addTo(this.group);
                        pts.push([d.lat, d.lng]);
                    });
                    if (pts.length) this.map.fitBounds(L.latLngBounds(pts).pad(0.3));
                },
        });
    </script>
</x-filament-panels::page>
