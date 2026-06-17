<x-filament-panels::page>
    @php
        $inputClass = 'fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5';
    @endphp

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            Tell us where each location is and how far you serve from it — we work out the towns you cover.
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
            $overlapByBase = collect($this->coverage['overlap_by_base'] ?? []);
        @endphp

        {{-- Header summary --}}
        @if ($computed)
            @php $union = $this->coverage['union'] ?? []; $overlap = $this->coverage['overlap_count'] ?? 0; @endphp
            <div class="flex items-center justify-between rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <span class="font-semibold text-gray-800 dark:text-gray-100">{{ count($union) }} towns</span>
                <span @class(['text-success-600 dark:text-success-400' => $overlap === 0, 'text-warning-600 dark:text-warning-400' => $overlap > 0])>
                    {{ $overlap === 0 ? 'no overlap' : $overlap.' overlapping' }}
                </span>
            </div>
        @endif

        {{-- Location cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2" @if ($locating) wire:poll.3s @endif>
            @foreach ($locations as $location)
                @php
                    $located = $location->lat !== null && $location->lng !== null;
                    $color = $colors[$location->id] ?? '#2563eb';
                    $reach = $overlapByBase->firstWhere('location_id', $location->id);
                @endphp
                <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-3 w-3 shrink-0 rounded-full" style="background: {{ $color }}"></span>
                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $location->name }}</span>
                        </div>
                        @if ($located)
                            <span class="rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300">● Located</span>
                        @elseif ($location->geocode_failed)
                            <span class="rounded-full bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 ring-1 ring-danger-600/20 dark:bg-danger-500/10 dark:text-danger-300">Couldn’t locate</span>
                        @else
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">locating…</span>
                        @endif
                    </div>

                    <div>
                        <div class="text-sm text-gray-600 dark:text-gray-300">{{ $location->address ?: 'No address on file' }}</div>
                        @if ($located)
                            <div class="text-xs text-gray-400">✓ Located automatically · {{ number_format((float) $location->lat, 3) }}, {{ number_format((float) $location->lng, 3) }}</div>
                        @endif
                    </div>

                    {{-- How far you serve — segmented control --}}
                    <div>
                        <div class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">How far you serve</div>
                        <div class="inline-flex overflow-hidden rounded-lg ring-1 ring-gray-300 dark:ring-white/10">
                            @foreach (\App\Filament\Pages\LocationsSetup::RADII as $r)
                                @php $active = (int) ($radii[$location->id] ?? 25) === $r; @endphp
                                <button type="button" wire:click="setRadius('{{ $location->id }}', {{ $r }})"
                                    @class([
                                        'px-3 py-1 text-sm',
                                        'bg-primary-600 text-white' => $active,
                                        'bg-white text-gray-600 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300' => ! $active,
                                    ])>{{ $r }} mi</button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Reach readout (from the computed coverage) --}}
                    @if ($reach)
                        @php
                            $parts = ['~'.$reach['total'].' towns'];
                            if ($reach['counties'] > 0) {
                                $parts[] = $reach['counties'].' '.\Illuminate\Support\Str::plural('county', $reach['counties']);
                            }
                            if (! empty($reach['states'])) {
                                $parts[] = implode(', ', $reach['states']);
                            }
                        @endphp
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ implode(' · ', $parts) }}
                            @if ($reach['shared'] > 0)
                                <span class="text-warning-600 dark:text-warning-400"> · {{ $reach['shared'] }} shared with {{ implode(' / ', $reach['shared_with']) }}</span>
                            @endif
                        </div>
                    @endif

                    {{-- Directed coverage: add a specific town beyond the radius --}}
                    @php
                        $myTowns = collect($this->coverage['union'] ?? [])
                            ->filter(fn ($m) => ($m['manual'] ?? false) && in_array($location->id, $m['source_location_ids'] ?? [], true));
                    @endphp
                    <div class="flex flex-col gap-2 border-t border-gray-100 pt-2 dark:border-white/10">
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Add a town (beyond the radius)</div>
                        @foreach ($myTowns as $t)
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-700 dark:text-gray-200">🚩 {{ $t['name'] }}@if ($t['state']) , {{ $t['state'] }}@endif <span class="text-warning-600 dark:text-warning-400">· priority page</span></span>
                                <button type="button" wire:click="removeTown('{{ $t['geo_id'] }}')" class="text-gray-400 hover:text-danger-600">remove</button>
                            </div>
                        @endforeach
                        <div class="flex gap-2">
                            <input type="text" wire:model="townQuery.{{ $location->id }}" wire:keydown.enter="searchTowns('{{ $location->id }}')"
                                placeholder="town name" class="{{ $inputClass }}" />
                            <x-filament::button wire:click="searchTowns('{{ $location->id }}')" size="sm" color="gray">Search</x-filament::button>
                        </div>
                        @foreach ($townResults[$location->id] ?? [] as $res)
                            <button type="button" wire:click="addTown('{{ $location->id }}', '{{ $res['geo_id'] }}')"
                                class="rounded-lg bg-gray-50 px-3 py-1.5 text-left text-xs ring-1 ring-gray-950/5 hover:bg-gray-100 dark:bg-white/5 dark:ring-white/10">
                                + {{ $res['name'] }}@if ($res['state']) , {{ $res['state'] }}@endif
                            </button>
                        @endforeach
                    </div>

                    {{-- Fallback only when locating failed: retry (now Google) or set the spot manually --}}
                    @if ($location->geocode_failed)
                        <div class="flex flex-col gap-2 border-t border-gray-100 pt-2 dark:border-white/10">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Couldn’t locate this address automatically.</span>
                                <x-filament::button wire:click="retryGeocode('{{ $location->id }}')" size="xs" color="gray" icon="heroicon-m-arrow-path">Retry locating</x-filament::button>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <label>
                                    <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Latitude</span>
                                    <input type="text" wire:model="manualLat.{{ $location->id }}" class="{{ $inputClass }} w-28" />
                                </label>
                                <label>
                                    <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Longitude</span>
                                    <input type="text" wire:model="manualLng.{{ $location->id }}" class="{{ $inputClass }} w-28" />
                                </label>
                                <x-filament::button wire:click="saveManualPoint('{{ $location->id }}')" size="sm" color="gray">Set the spot</x-filament::button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Add-location card --}}
            <div class="flex flex-col gap-3 rounded-xl border-2 border-dashed border-gray-200 p-4 dark:border-white/10">
                @if (! $adding)
                    <button type="button" wire:click="startAdd" class="flex h-full min-h-28 w-full flex-col items-center justify-center gap-1 text-sm text-gray-500 hover:text-primary-600 dark:text-gray-400">
                        <span class="text-2xl leading-none">＋</span>
                        Add location
                    </button>
                    @if ($locations->isEmpty())
                        <p class="text-center text-xs text-gray-400">No locations yet — add the first one.</p>
                    @endif
                @else
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

                    <label class="flex items-center justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-300">How far do you serve?</span>
                        <span class="inline-flex overflow-hidden rounded-lg ring-1 ring-gray-300 dark:ring-white/10">
                            @foreach (\App\Filament\Pages\LocationsSetup::RADII as $r)
                                <button type="button" wire:click="$set('addRadius', {{ $r }})" @class(['px-3 py-1', 'bg-primary-600 text-white' => $addRadius === $r, 'bg-white text-gray-600 dark:bg-gray-900 dark:text-gray-300' => $addRadius !== $r])>{{ $r }} mi</button>
                            @endforeach
                        </span>
                    </label>

                    <div class="flex gap-2">
                        @if ($addSource !== 'places' || ! $this->placesEnabled)
                            <x-filament::button wire:click="addManual" size="sm" icon="heroicon-m-check">Add location</x-filament::button>
                        @endif
                        <x-filament::button wire:click="cancelAdd" size="sm" color="gray">Cancel</x-filament::button>
                    </div>
                @endif
            </div>
        </div>

        {{-- Shared coverage map --}}
        @if (! $locations->isEmpty())
            <div class="rounded-xl bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div wire:ignore
                    x-data="coverageMap(@js($this->mapData), @js($this->manualMarkers))"
                    x-init="init()"
                    x-on:locations-updated.window="render($event.detail.data ?? [], $event.detail.manual ?? [])">
                    <div x-ref="map" class="h-[420px] w-full rounded-lg" style="background:#e5e7eb"></div>
                </div>
            </div>
            <div>
                <x-filament::button wire:click="compute" icon="heroicon-m-map">Update service area</x-filament::button>
            </div>
        @endif
    @endif

    {{-- Leaflet (OSM/CARTO tiles, no API key) --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script>
        // Defined as a plain global at parse time (NOT via alpine:init) so x-data can never
        // evaluate `coverageMap` before it exists — a "coverageMap is not defined" throw in
        // x-data would halt Alpine and, with it, ALL Livewire interactivity (the radius
        // buttons included). Every Leaflet touch is guarded so a failure degrades to
        // "no map", never a thrown init.
        window.coverageMap = (initial, initialManual) => ({
                map: null,
                group: null,
                init() {
                    try {
                        this.ensureLeaflet(() => {
                            try {
                                // The wire:ignore container persists across Livewire morphs, but the
                                // Alpine component can re-init — REUSE the existing Leaflet map instead
                                // of calling L.map() on it twice ("Map container is already initialized",
                                // which uncaught would halt Alpine and kill every wire:click on the page).
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
                                this.render(initial, initialManual);
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
                render(data, manual) {
                    if (!this.map || !window.L) return;
                    if (this.group) this.map.removeLayer(this.group);
                    this.group = L.layerGroup().addTo(this.map);
                    const pts = [];
                    (data || []).forEach((d) => {
                        if (d.lat == null || d.lng == null) return;
                        L.circle([d.lat, d.lng], {
                            radius: d.radius * 1609.34,
                            color: d.color, weight: 2, fillColor: d.color, fillOpacity: 0.16,
                        }).addTo(this.group);
                        L.circleMarker([d.lat, d.lng], { radius: 5, color: d.color, fillColor: d.color, fillOpacity: 1 })
                            .bindTooltip(d.name, { permanent: false }).addTo(this.group);
                        pts.push([d.lat, d.lng]);
                    });
                    // Manually-added towns: a distinct flag marker, NO circle — directed coverage reads differently.
                    (manual || []).forEach((d) => {
                        if (d.lat == null || d.lng == null) return;
                        L.marker([d.lat, d.lng], {
                            icon: L.divIcon({ html: '🚩', className: 'lp-flag', iconSize: [18, 18], iconAnchor: [4, 16] }),
                        }).bindTooltip(d.name + ' (added)', { permanent: false }).addTo(this.group);
                        pts.push([d.lat, d.lng]);
                    });
                    if (pts.length) this.map.fitBounds(L.latLngBounds(pts).pad(0.4));
                },
        });
    </script>
</x-filament-panels::page>
