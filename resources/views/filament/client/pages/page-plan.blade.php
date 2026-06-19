{{-- The client's page plan: the inventory the engine built + the lead-upside (search demand
     we target). Honest framing — volume is the size of the audience searching, never a promised
     or attributed lead count. Read-only, with one action: sign off on the plan. --}}
<x-filament-panels::page>
    @php
        $plan = $this->plan;
        $approval = $this->approval;
        $totals = $plan['totals'];
    @endphp

    @if (count($this->siteOptions) > 1)
        <x-filament::input.wrapper class="max-w-xs">
            <x-filament::input.select wire:model.live="siteId">
                @foreach ($this->siteOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    @endif

    <x-filament::section>
        <x-slot name="heading">Your content plan</x-slot>
        <x-slot name="description">
            The pages we'll publish for you, grouped by topic. Each number is the monthly search
            demand we're targeting — the size of the audience already looking for this work.
        </x-slot>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($totals['silos']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Topic areas</div>
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($totals['pages']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Pages we'll build</div>
            </div>
            <div>
                <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($totals['sections']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Related topics covered</div>
            </div>
            <div>
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($totals['volume']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Monthly searches targeted</div>
            </div>
        </div>

        <x-slot name="footerActions">
            @if ($approval['approved'])
                <x-filament::badge color="success" icon="heroicon-m-check-badge">
                    Approved{{ $approval['at'] ? ' on '.$approval['at']->format('M j, Y') : '' }}
                </x-filament::badge>
            @elseif ($totals['pages'] > 0)
                <x-filament::button wire:click="approve" icon="heroicon-m-check">
                    Approve this plan
                </x-filament::button>
            @endif
        </x-slot>
    </x-filament::section>

    @forelse ($plan['silos'] as $silo)
        <x-filament::section collapsible>
            <x-slot name="heading">{{ $silo['name'] }}</x-slot>
            <x-slot name="description">
                {{ $silo['page_count'] }} {{ \Illuminate\Support\Str::plural('page', $silo['page_count']) }}
                · {{ number_format($silo['volume']) }} monthly searches targeted
            </x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($silo['pages'] as $page)
                    <div class="flex items-start justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $page['name'] }}</span>
                                @if ($page['kind'] === 'hub')
                                    <x-filament::badge color="primary" size="sm">Hub</x-filament::badge>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">{{ $page['keyword'] }}</div>
                            @if (count($page['sections']) > 0)
                                <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    Also covers: {{ collect($page['sections'])->pluck('name')->join(' · ') }}
                                </div>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-sm font-bold text-primary-600 dark:text-primary-400">{{ number_format($page['volume']) }}</div>
                            <div class="text-[11px] text-gray-400">/mo searches</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Your page plan is being prepared — check back shortly.
            </p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
