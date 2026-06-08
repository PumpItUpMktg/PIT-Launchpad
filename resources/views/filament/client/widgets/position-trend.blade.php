{{-- Position trend with refresh markers shown as observed correlation only.
     No causal claims — the markers are date annotations; the data speaks for itself. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Position trend</x-slot>
        <x-slot name="description">
            Refresh markers show when content was updated — shown as observed correlation, not attributed impact.
        </x-slot>

        @if ($keyword === null || $trend === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">No tracked positions yet.</p>
        @else
            <div class="space-y-4">
                <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $keyword->query }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        Standing:
                        <strong>#{{ $trend['standings']['primary'] ?? '—' }}</strong>
                        @if ($trend['standings']['secondary']) / #{{ $trend['standings']['secondary'] }} @endif
                        @if ($trend['standings']['tertiary']) / #{{ $trend['standings']['tertiary'] }} @endif
                        @if ($trend['standings']['as_of']) <span class="text-gray-400">as of {{ $trend['standings']['as_of'] }}</span> @endif
                    </span>
                </div>

                <div class="flex items-end gap-1 overflow-x-auto" style="height: 5rem;">
                    @php $maxRank = max(1, collect($trend['series'])->pluck('rank')->filter()->max() ?? 1); @endphp
                    @foreach ($trend['series'] as $point)
                        @php $rank = $point['rank']; @endphp
                        <div class="flex flex-col items-center justify-end" title="{{ $point['date'] }}: #{{ $rank ?? '—' }}">
                            <div class="w-3 rounded-t bg-primary-500"
                                 style="height: {{ $rank ? max(6, 80 - ($rank / $maxRank * 70)) : 2 }}px;"></div>
                        </div>
                    @endforeach
                </div>

                @if (count($trend['refresh_markers']) > 0)
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-medium">Refresh markers (correlation):</span>
                        @foreach ($trend['refresh_markers'] as $marker)
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 dark:bg-gray-800">
                                ↻ {{ $marker['date'] }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
