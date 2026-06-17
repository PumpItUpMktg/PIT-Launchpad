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

    @if ($siteId)
        @php
            $locations = $this->locations;
            $locating = $locations->contains(fn ($l) => $l->lat === null && ! $l->geocode_failed);
        @endphp

        {{-- Locations list (poll while anything is still locating) --}}
        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            @if ($locating) wire:poll.3s @endif>
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Your locations</h3>
                @unless ($adding)
                    <x-filament::button wire:click="startAdd" size="sm" icon="heroicon-m-plus">Add location</x-filament::button>
                @endunless
            </div>

            @if ($locations->isEmpty() && ! $adding)
                <p class="text-sm text-gray-500 dark:text-gray-400">No locations yet — add the first one.</p>
            @endif

            @foreach ($locations as $location)
                @php $located = $location->lat !== null && $location->lng !== null; @endphp
                <div class="flex flex-col gap-2 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-800 dark:text-gray-100">{{ $location->name }}</div>
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $location->address ?: 'No address on file' }}</div>
                            <div class="mt-1 text-xs">
                                @if ($located)
                                    <span class="text-success-600 dark:text-success-400">✓ located</span>
                                @elseif ($location->geocode_failed)
                                    <span class="text-danger-600 dark:text-danger-400">We couldn’t find this address — enter the spot below.</span>
                                @else
                                    <span class="text-gray-400">locating…</span>
                                @endif
                            </div>
                        </div>
                        <label class="flex shrink-0 flex-col items-end gap-1 text-sm">
                            <span class="text-xs text-gray-500 dark:text-gray-400">How far do you serve?</span>
                            @php
                                $opts = collect(\App\Filament\Pages\LocationsSetup::RADII)
                                    ->push((int) ($radii[$location->id] ?? \App\Filament\Pages\LocationsSetup::DEFAULT_RADIUS))
                                    ->unique()->sort()->values();
                            @endphp
                            <select wire:model="radii.{{ $location->id }}" class="{{ $inputClass }} w-32">
                                @foreach ($opts as $r)
                                    <option value="{{ $r }}">{{ $r }} miles</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    {{-- Manual override — only when locating failed --}}
                    @if ($location->geocode_failed)
                        <div class="flex flex-wrap items-end gap-2 border-t border-gray-100 pt-2 dark:border-white/10">
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
                    @endif
                </div>
            @endforeach

            {{-- Add-location flow --}}
            @if ($adding)
                <div class="flex flex-col gap-3 rounded-lg border border-primary-200 bg-primary-50/40 p-3 dark:border-primary-500/30 dark:bg-primary-500/5">
                    @if ($this->placesEnabled)
                        <div class="flex gap-2 text-sm">
                            <button type="button" wire:click="$set('addSource', 'places')" @class(['rounded-lg px-3 py-1', 'bg-primary-600 text-white' => $addSource === 'places', 'text-gray-600 dark:text-gray-300' => $addSource !== 'places'])>From Google</button>
                            <button type="button" wire:click="$set('addSource', 'manual')" @class(['rounded-lg px-3 py-1', 'bg-primary-600 text-white' => $addSource === 'manual', 'text-gray-600 dark:text-gray-300' => $addSource !== 'manual'])>Enter manually</button>
                        </div>
                    @endif

                    @if ($addSource === 'places' && $this->placesEnabled)
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <label class="flex-1">
                                <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Find your business</span>
                                <input type="text" wire:model="addQuery" wire:keydown.enter="searchPlaces" placeholder="business name or address" class="{{ $inputClass }}" />
                            </label>
                            <x-filament::button wire:click="searchPlaces" color="gray" icon="heroicon-m-magnifying-glass">Search</x-filament::button>
                        </div>
                        @foreach ($placeResults as $r)
                            <button type="button" wire:click="addFromPlace('{{ $r['place_id'] }}')"
                                class="flex flex-col items-start rounded-lg bg-white px-3 py-2 text-left text-sm ring-1 ring-gray-950/5 hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10">
                                <span class="font-medium text-gray-800 dark:text-gray-100">{{ $r['name'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $r['address'] }}</span>
                            </button>
                        @endforeach
                    @else
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label>
                                <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Location name</span>
                                <input type="text" wire:model="addName" placeholder="e.g. Montclair" class="{{ $inputClass }}" />
                            </label>
                            <label>
                                <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Where you are (address)</span>
                                <input type="text" wire:model="addAddress" placeholder="123 Main St, Town, ST" class="{{ $inputClass }}" />
                            </label>
                        </div>
                    @endif

                    {{-- Coverage question, asked at add-time in the owner's words --}}
                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-gray-600 dark:text-gray-300">How far do you serve from here?</span>
                        <select wire:model="addRadius" class="{{ $inputClass }} w-32">
                            @foreach (\App\Filament\Pages\LocationsSetup::RADII as $r)
                                <option value="{{ $r }}">{{ $r }} miles</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex gap-2">
                        @if ($addSource !== 'places' || ! $this->placesEnabled)
                            <x-filament::button wire:click="addManual" icon="heroicon-m-check">Add location</x-filament::button>
                        @endif
                        <x-filament::button wire:click="cancelAdd" color="gray">Cancel</x-filament::button>
                    </div>
                </div>
            @endif

            @if (! $locations->isEmpty())
                <div>
                    <x-filament::button wire:click="compute" icon="heroicon-m-map">Update service area</x-filament::button>
                </div>
            @endif
        </div>

        {{-- Coverage union --}}
        @if ($computed)
            @php
                $union = $this->coverage['union'] ?? [];
                $places = collect($union)->where('type', 'place')->count();
                $mcds = collect($union)->where('type', 'county_subdivision')->count();
            @endphp
            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-baseline justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Towns you cover</h3>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($union) }} towns · {{ $places }} places · {{ $mcds }} townships/boroughs</span>
                </div>
                @if (count($union))
                    <div class="overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium">Town</th>
                                    <th class="px-3 py-2 text-left font-medium">Type</th>
                                    <th class="px-3 py-2 text-left font-medium">State</th>
                                    <th class="px-3 py-2 text-right font-medium">Distance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($union as $m)
                                    <tr>
                                        <td class="px-3 py-1.5 text-gray-800 dark:text-gray-100">{{ $m['name'] }}</td>
                                        <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ \App\Enums\MunicipalityType::from($m['type'])->label() }}</td>
                                        <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ $m['state'] ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-right text-gray-500 dark:text-gray-400">{{ number_format((float) $m['distance_miles'], 1) }} mi</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>
