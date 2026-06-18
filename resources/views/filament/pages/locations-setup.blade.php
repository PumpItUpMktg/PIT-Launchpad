<x-filament-panels::page>
    @php
        $inputClass = 'fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5';
        // The 4-tier ramp (mockup v3) + ungrouped.
        $tierMeta = [
            'major' => ['label' => 'Major', 'color' => '#0A4F4F'],
            'large' => ['label' => 'Large', 'color' => '#0E6B6B'],
            'medium' => ['label' => 'Medium', 'color' => '#4E9A98'],
            'small' => ['label' => 'Small', 'color' => '#A6CFCD'],
            'ungrouped' => ['label' => 'Ungrouped', 'color' => '#C3CCD6'],
        ];
    @endphp

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            Tell us where each location is and which counties you serve — then pick the towns you want location pages for.
        </p>
        <label class="block max-w-sm">
            <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Site</span>
            <select wire:model.live="siteId" class="{{ $inputClass }}">
                <option value="">Select a site…</option>
                @foreach ($this->siteOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($this->geocoderWarning)
        <div class="rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300">
            ⚠ {{ $this->geocoderWarning }}
        </div>
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
        @endphp

        {{-- Totals hero --}}
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex gap-8">
                    <div>
                        <div class="text-3xl font-bold text-gray-900 dark:text-gray-50">{{ $totals['covered'] }}</div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">towns covered</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold" style="color:#0A4F4F">{{ $totals['selected'] }}</div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">selected for pages</div>
                    </div>
                </div>
                @php $overlap = $totals['overlap'] ?? 0; @endphp
                <span @class([
                    'rounded-full px-3 py-1 text-xs font-medium ring-1',
                    'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300' => $overlap === 0,
                    'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300' => $overlap > 0,
                ])>{{ $overlap === 0 ? 'no overlap' : $overlap.' overlapping' }}</span>
            </div>

            {{-- 4-tier distribution bar + legend --}}
            @if ($totals['covered'] > 0)
                <div class="mt-4 flex h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                    @foreach ($tierMeta as $key => $meta)
                        @php $n = $tierTotals[$key] ?? 0; $pct = $totals['covered'] > 0 ? ($n / $totals['covered']) * 100 : 0; @endphp
                        @if ($n > 0)
                            <div style="width: {{ $pct }}%; background: {{ $meta['color'] }}" title="{{ $meta['label'] }}: {{ $n }}"></div>
                        @endif
                    @endforeach
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    @foreach ($tierMeta as $key => $meta)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $meta['color'] }}"></span>
                            {{ $meta['label'] }} {{ $tierTotals[$key] ?? 0 }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Location tabs --}}
        <div class="flex flex-wrap items-center gap-2" @if ($locating) wire:poll.3s @endif>
            @foreach ($locations as $location)
                @php
                    $isActive = $activeLoc && $location->id === $activeLoc->id && ! $adding;
                    $color = $colors[$location->id] ?? '#2563eb';
                    $p = $vm['panels'][$location->id] ?? ['town_count' => 0, 'selected_count' => 0];
                @endphp
                <button type="button" wire:click="$set('activeTab', '{{ $location->id }}')"
                    @class([
                        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 transition',
                        'bg-white ring-primary-500 dark:bg-gray-900' => $isActive,
                        'bg-gray-50 ring-gray-950/5 hover:bg-gray-100 dark:bg-white/5 dark:ring-white/10' => ! $isActive,
                    ])>
                    <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background: {{ $color }}"></span>
                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ $location->name }}</span>
                    <span class="text-xs text-gray-400">{{ $p['town_count'] }}</span>
                    @if ($p['selected_count'] > 0)
                        <span class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold text-white" style="background:#0A4F4F">{{ $p['selected_count'] }}</span>
                    @endif
                </button>
            @endforeach
            <button type="button" wire:click="startAdd"
                @class(['flex items-center gap-1 rounded-lg border-2 border-dashed px-3 py-2 text-sm', 'border-primary-400 text-primary-600' => $adding, 'border-gray-200 text-gray-500 hover:text-primary-600 dark:border-white/10' => ! $adding])>
                ＋ Add location
            </button>
        </div>

        {{-- Add-location panel --}}
        @if ($adding)
            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @if ($this->placesEnabled)
                    <div class="flex gap-2 text-sm">
                        <button type="button" wire:click="$set('addSource', 'places')" @class(['rounded-lg px-3 py-1', 'bg-primary-600 text-white' => $addSource === 'places', 'text-gray-600 dark:text-gray-300' => $addSource !== 'places'])>From Google</button>
                        <button type="button" wire:click="$set('addSource', 'manual')" @class(['rounded-lg px-3 py-1', 'bg-primary-600 text-white' => $addSource === 'manual', 'text-gray-600 dark:text-gray-300' => $addSource !== 'manual'])>Enter manually</button>
                    </div>
                @endif

                @if ($addSource === 'places' && $this->placesEnabled)
                    <div class="flex flex-col gap-2">
                        <input type="text" wire:model="addQuery" wire:keydown.enter="searchPlaces" placeholder="business name or address" class="{{ $inputClass }}" />
                        <x-filament::button wire:click="searchPlaces" color="gray" size="sm" icon="heroicon-m-magnifying-glass">Search</x-filament::button>
                        @foreach ($placeResults as $r)
                            <button type="button" wire:click="addFromPlace('{{ $r['place_id'] }}')" class="rounded-lg bg-gray-50 px-3 py-2 text-left text-sm ring-1 ring-gray-950/5 hover:bg-gray-100 dark:bg-white/5 dark:ring-white/10">
                                <span class="font-medium text-gray-800 dark:text-gray-100">{{ $r['name'] }}</span>
                                <span class="block text-xs text-gray-500">{{ $r['address'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <input type="text" wire:model="addName" placeholder="Location name (e.g. Montclair)" class="{{ $inputClass }}" />
                    <input type="text" wire:model="addAddress" placeholder="Where you are (address)" class="{{ $inputClass }}" />
                @endif

                <p class="text-xs text-gray-400">We’ll locate it and pre-tick its home county — adjust the counties you serve on the tab.</p>

                <div class="flex gap-2">
                    @if ($addSource !== 'places' || ! $this->placesEnabled)
                        <x-filament::button wire:click="addManual" size="sm" icon="heroicon-m-check">Add location</x-filament::button>
                    @endif
                    <x-filament::button wire:click="cancelAdd" size="sm" color="gray">Cancel</x-filament::button>
                </div>
            </div>
        @elseif ($activeLoc)
            {{-- Active location panel --}}
            @php
                $located = $activeLoc->lat !== null && $activeLoc->lng !== null;
                $selectedCounties = is_array($activeLoc->county_geoids) ? $activeLoc->county_geoids : [];
                $countyOptions = $located ? $this->countyOptions($activeLoc) : [];
                $color = $colors[$activeLoc->id] ?? '#2563eb';
            @endphp
            <div class="flex flex-col gap-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                {{-- Located header --}}
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background: {{ $color }}"></span>
                        <div>
                            <div class="font-semibold text-gray-800 dark:text-gray-100">{{ $activeLoc->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $activeLoc->address ?: 'No address on file' }}</div>
                            @if ($located)
                                <div class="text-xs text-gray-400">✓ Located · {{ number_format((float) $activeLoc->lat, 3) }}, {{ number_format((float) $activeLoc->lng, 3) }}</div>
                            @endif
                        </div>
                    </div>
                    @if ($located)
                        <span class="rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300">● Located</span>
                    @elseif ($activeLoc->geocode_failed)
                        <span class="rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300">Couldn’t locate</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">locating…</span>
                    @endif
                </div>

                {{-- Counties served --}}
                @if ($located)
                    <div>
                        <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Counties you serve</div>
                        @if ($countyOptions === [])
                            <div class="text-xs text-gray-400">No counties found for this state.</div>
                        @else
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($countyOptions as $county)
                                    @php
                                        $checked = in_array($county['geo_id'], $selectedCounties, true);
                                        $isHome = $county['geo_id'] === $activeLoc->home_county_geoid;
                                        $countyLabel = ($checked ? '✓ ' : '').$county['name'].($isHome ? ' (home)' : '');
                                    @endphp
                                    <button type="button" wire:click="toggleCounty('{{ $activeLoc->id }}', '{{ $county['geo_id'] }}')"
                                        @class([
                                            'rounded-full px-2.5 py-1 text-xs ring-1 transition',
                                            'bg-primary-600 text-white ring-primary-600' => $checked,
                                            'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10' => ! $checked,
                                        ])>{{ $countyLabel }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- locstat + tier minibar --}}
                @if ($activePanel && $activePanel['town_count'] > 0)
                    @php $pt = $activePanel['tiers']; @endphp
                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3 text-sm dark:border-white/10">
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $activePanel['town_count'] }} towns</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold text-white" style="background:#0A4F4F">{{ $activePanel['selected_count'] }} selected</span>
                        <div class="flex h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5" style="min-width:120px">
                            @foreach ($tierMeta as $key => $meta)
                                @php $n = $pt[$key] ?? 0; $pct = $activePanel['town_count'] > 0 ? ($n / $activePanel['town_count']) * 100 : 0; @endphp
                                @if ($n > 0)
                                    <div style="width: {{ $pct }}%; background: {{ $meta['color'] }}"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    {{-- Town groups by tier --}}
                    <div class="flex flex-col gap-2">
                        @foreach ($tierMeta as $key => $meta)
                            @php $towns = $activePanel['groups'][$key] ?? []; @endphp
                            @if (count($towns) > 0)
                                @php $selInTier = collect($towns)->where('page_selected', true)->count(); @endphp
                                <div x-data="{ open: true }" class="rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                                        <button type="button" x-on:click="open = ! open" class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                                            <span class="inline-block h-2.5 w-2.5 rounded-sm" style="background: {{ $meta['color'] }}"></span>
                                            {{ $meta['label'] }}
                                            <span class="text-xs text-gray-400">{{ $selInTier }} / {{ count($towns) }}</span>
                                        </button>
                                        <div class="flex gap-1 text-xs">
                                            <button type="button" wire:click="selectTier('{{ $activeLoc->id }}', '{{ $key }}', true)" class="rounded px-2 py-0.5 text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10">Select all</button>
                                            <button type="button" wire:click="selectTier('{{ $activeLoc->id }}', '{{ $key }}', false)" class="rounded px-2 py-0.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5">Clear</button>
                                        </div>
                                    </div>
                                    <div x-show="open" class="flex flex-wrap gap-1.5 border-t border-gray-100 p-3 dark:border-white/10">
                                        @foreach ($towns as $town)
                                            @php $pop = $town['population'] !== null ? number_format($town['population']) : '—'; @endphp
                                            <button type="button" wire:click="togglePageSelection('{{ $town['geo_id'] }}')"
                                                @class([
                                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs ring-1 transition',
                                                    'bg-primary-600 text-white ring-primary-600' => $town['page_selected'],
                                                    'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10' => ! $town['page_selected'],
                                                ])>
                                                {{ $town['page_selected'] ? '✓' : '+' }} {{ $town['name'] }}
                                                @if ($town['manual'])<span class="opacity-70">🚩</span>@endif
                                                <span class="opacity-60">{{ $pop }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @elseif ($located)
                    <div class="border-t border-gray-100 pt-3 text-sm text-gray-400 dark:border-white/10">Tick a county above to enumerate its towns.</div>
                @endif

                {{-- Add a town beyond the served counties --}}
                @if ($located)
                    <div class="flex flex-col gap-2 border-t border-gray-100 pt-3 dark:border-white/10">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Add a town (beyond the served counties)</div>
                        <div class="flex gap-2">
                            <input type="text" wire:model="townQuery.{{ $activeLoc->id }}" wire:keydown.enter="searchTowns('{{ $activeLoc->id }}')" placeholder="town name" class="{{ $inputClass }}" />
                            <x-filament::button wire:click="searchTowns('{{ $activeLoc->id }}')" size="sm" color="gray">Search</x-filament::button>
                        </div>
                        @foreach ($townResults[$activeLoc->id] ?? [] as $res)
                            <button type="button" wire:click="addTown('{{ $activeLoc->id }}', '{{ $res['geo_id'] }}')"
                                class="rounded-lg bg-gray-50 px-3 py-1.5 text-left text-xs ring-1 ring-gray-950/5 hover:bg-gray-100 dark:bg-white/5 dark:ring-white/10">
                                + {{ $res['name'] }}@if ($res['state']) , {{ $res['state'] }}@endif
                            </button>
                        @endforeach
                    </div>
                @endif

                {{-- Failed-geocode fallback --}}
                @if ($activeLoc->geocode_failed)
                    <div class="flex flex-col gap-2 border-t border-gray-100 pt-3 dark:border-white/10">
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-gray-500 dark:text-gray-400">Couldn’t locate this address automatically.</span>
                            <x-filament::button wire:click="retryGeocode('{{ $activeLoc->id }}')" size="xs" color="gray" icon="heroicon-m-arrow-path">Retry locating</x-filament::button>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <label>
                                <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Latitude</span>
                                <input type="text" wire:model="manualLat.{{ $activeLoc->id }}" class="{{ $inputClass }} w-28" />
                            </label>
                            <label>
                                <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Longitude</span>
                                <input type="text" wire:model="manualLng.{{ $activeLoc->id }}" class="{{ $inputClass }} w-28" />
                            </label>
                            <x-filament::button wire:click="saveManualPoint('{{ $activeLoc->id }}')" size="sm" color="gray">Set the spot</x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        @elseif ($locations->isEmpty())
            <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-400 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">No locations yet — add the first one.</p>
        @endif

        {{-- Shared coverage map (pins per base + flagged directed towns) --}}
        @if (! $locations->isEmpty())
            <div class="rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div wire:ignore
                    x-data="coverageMap(@js($this->mapData), @js($this->manualMarkers), @js($this->countyPolygons))"
                    x-init="init()"
                    x-on:locations-updated.window="render($event.detail.data ?? [], $event.detail.manual ?? [], $event.detail.polygons ?? [])">
                    <div x-ref="map" class="h-[380px] w-full rounded-lg" style="background:#e5e7eb"></div>
                </div>
            </div>

            {{-- Bottom bar: live selected · covered (from the persisted rows — counts agree with the hero) --}}
            <div class="sticky bottom-2 flex items-center justify-between rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-lg dark:bg-gray-800">
                <span><span class="font-bold">{{ $totals['selected'] }}</span> selected · <span class="font-bold">{{ $totals['covered'] }}</span> covered</span>
                <x-filament::button wire:click="compute" icon="heroicon-m-map" size="sm">Update service area</x-filament::button>
            </div>
        @endif
    @endif

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
                    // Served counties: outline each county's boundary (no radius circles).
                    (polygons || []).forEach((c) => {
                        (c.rings || []).forEach((ring) => {
                            if (!ring || !ring.length) return;
                            const latlngs = ring.map((p) => [p.lat, p.lng]);
                            L.polygon(latlngs, { color: '#0E6B6B', weight: 2, fillColor: '#0E6B6B', fillOpacity: 0.07 })
                                .bindTooltip((c.name ? c.name + ' County' : 'County'), { permanent: false }).addTo(this.group);
                            latlngs.forEach((ll) => pts.push(ll));
                        });
                    });
                    // Base locations: a colored pin.
                    (data || []).forEach((d) => {
                        if (d.lat == null || d.lng == null) return;
                        L.circleMarker([d.lat, d.lng], { radius: 6, color: d.color, fillColor: d.color, fillOpacity: 1 })
                            .bindTooltip(d.name, { permanent: false }).addTo(this.group);
                        pts.push([d.lat, d.lng]);
                    });
                    // Manually-added towns: a distinct flag marker.
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
