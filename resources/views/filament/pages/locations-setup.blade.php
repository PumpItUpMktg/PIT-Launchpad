<x-filament-panels::page>
    @php
        $inputClass = 'fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5';
    @endphp

    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            Set a service radius on each base location, then compute the service-area coverage — the municipalities
            (towns + townships, cross-border where the radius reaches) that <code>silo-volume</code> localizes to.
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
        @php $locations = $this->locations; @endphp

            {{-- Base locations: address → geocode → point + radius --}}
            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Base locations</h3>

                @if ($locations->isEmpty())
                    <p class="text-sm text-warning-600 dark:text-warning-400">No base locations yet — add one below.</p>
                @endif

                @foreach ($locations as $location)
                    @php $hasPoint = $location->lat !== null && $location->lng !== null; @endphp
                    <div class="flex flex-col gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex items-center justify-between">
                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $location->name }}</span>
                            @if ($hasPoint)
                                <span class="text-xs text-success-600 dark:text-success-400">● {{ number_format((float) $location->lat, 5) }}, {{ number_format((float) $location->lng, 5) }}</span>
                            @else
                                <span class="text-xs text-warning-600 dark:text-warning-400">○ no point yet</span>
                            @endif
                        </div>

                        {{-- Address + geocode --}}
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <label class="flex-1">
                                <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Address</span>
                                <input type="text" wire:model="addresses.{{ $location->id }}" placeholder="123 Main St, Town, ST"
                                    class="{{ $inputClass }}" />
                            </label>
                            <x-filament::button wire:click="geocode('{{ $location->id }}')" color="gray" icon="heroicon-m-map-pin">
                                Geocode
                            </x-filament::button>
                        </div>

                        {{-- Manual fallback + radius --}}
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div class="flex items-end gap-2">
                                <label>
                                    <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Lat</span>
                                    <input type="text" wire:model="manualLat.{{ $location->id }}" class="{{ $inputClass }} w-28" />
                                </label>
                                <label>
                                    <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Lng</span>
                                    <input type="text" wire:model="manualLng.{{ $location->id }}" class="{{ $inputClass }} w-28" />
                                </label>
                                <x-filament::button wire:click="saveManualPoint('{{ $location->id }}')" color="gray" size="sm">Set point</x-filament::button>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <span class="text-gray-500 dark:text-gray-400">Radius</span>
                                @php
                                    // Presets plus any non-preset value already saved (e.g. via the CLI) so it shows, not silently snaps.
                                    $opts = collect(\App\Filament\Pages\LocationsSetup::RADII)
                                        ->push((int) ($radii[$location->id] ?? \App\Filament\Pages\LocationsSetup::DEFAULT_RADIUS))
                                        ->unique()->sort()->values();
                                @endphp
                                <select wire:model="radii.{{ $location->id }}" class="{{ $inputClass }} w-28">
                                    @foreach ($opts as $r)
                                        <option value="{{ $r }}">{{ $r }} mi</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </div>
                @endforeach

                {{-- Add a base location --}}
                <div class="flex flex-col gap-2 border-t border-gray-100 pt-3 dark:border-white/10 sm:flex-row sm:items-end">
                    <label class="sm:w-40">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">New location name</span>
                        <input type="text" wire:model="newName" placeholder="HQ" class="{{ $inputClass }}" />
                    </label>
                    <label class="flex-1">
                        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Address</span>
                        <input type="text" wire:model="newAddress" placeholder="123 Main St, Town, ST" class="{{ $inputClass }}" />
                    </label>
                    <x-filament::button wire:click="addLocation" color="gray" icon="heroicon-m-plus">Add base location</x-filament::button>
                </div>

                @if (! $locations->isEmpty())
                    <div>
                        <x-filament::button wire:click="compute" icon="heroicon-m-map">Compute coverage</x-filament::button>
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
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Coverage union</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($union) }} municipalities · {{ $places }} places · {{ $mcds }} townships/boroughs</span>
                    </div>

                    @if (count($union))
                        <div class="overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                            <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-white/5">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-medium">Municipality</th>
                                        <th class="px-3 py-2 text-left font-medium">Type</th>
                                        <th class="px-3 py-2 text-left font-medium">State</th>
                                        <th class="px-3 py-2 text-right font-medium">Distance</th>
                                        <th class="px-3 py-2 text-right font-medium">Bases</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                    @foreach ($union as $m)
                                        <tr>
                                            <td class="px-3 py-1.5 text-gray-800 dark:text-gray-100">{{ $m['name'] }}</td>
                                            <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ \App\Enums\MunicipalityType::from($m['type'])->label() }}</td>
                                            <td class="px-3 py-1.5 text-gray-500 dark:text-gray-400">{{ $m['state'] ?? '—' }}</td>
                                            <td class="px-3 py-1.5 text-right text-gray-500 dark:text-gray-400">{{ number_format((float) $m['distance_miles'], 1) }} mi</td>
                                            <td class="px-3 py-1.5 text-right text-gray-500 dark:text-gray-400">{{ count($m['source_location_ids'] ?? []) }}</td>
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
