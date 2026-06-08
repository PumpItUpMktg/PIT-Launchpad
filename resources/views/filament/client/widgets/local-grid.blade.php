{{-- Per-market local-pack heatmap: the client's local search footprint. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Local visibility</x-slot>
        <x-slot name="description">Local-pack standing per market.</x-slot>

        @if (count($markets) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No local rankings tracked yet.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($markets as $market)
                    @php
                        $rank = $market['rank'];
                        $tone = $rank === null ? 'bg-gray-100 text-gray-500 dark:bg-gray-800'
                            : ($rank <= 3 ? 'bg-success-500/15 text-success-700 dark:text-success-400'
                            : ($rank <= 10 ? 'bg-warning-500/15 text-warning-700 dark:text-warning-400'
                            : 'bg-danger-500/10 text-danger-700 dark:text-danger-400'));
                    @endphp
                    <div class="rounded-lg p-3 {{ $tone }}">
                        <div class="text-xs font-medium truncate">{{ $market['market_name'] }}</div>
                        <div class="mt-1 text-2xl font-bold">{{ $rank !== null ? '#'.$rank : '—' }}</div>
                        @if ($market['captured_at'])
                            <div class="text-[11px] opacity-70">{{ $market['captured_at'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
