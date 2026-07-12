{{-- The active location's panel: located header, counties-served multi-select, tiered towns
     with page selection, directed add-a-town, and the failed-geocode fallback. Expects
     $activeLoc, $activePanel, $colors, $tierMeta from the including blade. --}}
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
