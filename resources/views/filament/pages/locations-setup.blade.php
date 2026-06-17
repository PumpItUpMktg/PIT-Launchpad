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

        @if ($locations->isEmpty())
            <div class="rounded-xl bg-warning-50 p-4 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300">
                No base locations for this site yet — import them (Places) first.
            </div>
        @else
            {{-- Base locations + radius --}}
            <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Base locations</h3>
                @foreach ($locations as $location)
                    <div class="flex flex-col gap-2 rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-950/5 sm:flex-row sm:items-center sm:justify-between dark:bg-white/5 dark:ring-white/10">
                        <div class="text-sm">
                            <span class="font-medium text-gray-800 dark:text-gray-100">{{ $location->name }}</span>
                            @if ($location->lat !== null && $location->lng !== null)
                                <span class="ml-2 text-xs text-gray-400">{{ number_format((float) $location->lat, 4) }}, {{ number_format((float) $location->lng, 4) }}</span>
                            @else
                                <span class="ml-2 text-xs text-warning-600 dark:text-warning-400">no coordinates — re-import to geocode</span>
                            @endif
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
                @endforeach

                <div>
                    <x-filament::button wire:click="compute" icon="heroicon-m-map">Compute coverage</x-filament::button>
                </div>
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
    @endif
</x-filament-panels::page>
