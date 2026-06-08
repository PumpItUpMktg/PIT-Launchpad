{{-- The performance face of each published page: position, refresh history, date.
     Shows what exists — no fabricated traffic or attributed ROI. --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Content performance</x-slot>
        <x-slot name="description">Every published page and how it performs.</x-slot>

        @if (count($cards) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No published content yet.</p>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cards as $card)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm font-semibold text-gray-950 dark:text-white line-clamp-2">{{ $card['title'] }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate">/{{ $card['slug'] }}</div>
                        <div class="mt-3 flex items-center justify-between text-xs">
                            <span class="inline-flex flex-col">
                                <span class="text-gray-400">Best position</span>
                                <span class="text-base font-bold text-primary-600 dark:text-primary-400">
                                    {{ $card['best_rank'] !== null ? '#'.$card['best_rank'] : '—' }}
                                </span>
                            </span>
                            <span class="inline-flex flex-col">
                                <span class="text-gray-400">Refreshes</span>
                                <span class="text-base font-bold text-gray-700 dark:text-gray-300">↻ {{ $card['refresh_count'] }}</span>
                            </span>
                            <span class="inline-flex flex-col text-right">
                                <span class="text-gray-400">Published</span>
                                <span class="text-gray-700 dark:text-gray-300">{{ $card['published_at'] ?? '—' }}</span>
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
