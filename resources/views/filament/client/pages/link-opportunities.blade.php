{{-- The client's link opportunities: who could link to them, what saying yes means, one decision per
     row. Honest framing — a local link is one signal among many, never a promised ranking. --}}
<x-filament-panels::page>
    @php
        $board = $this->board;
        $t = $board['totals'];
        $list = match ($this->show) { 'interested' => $board['interested'], 'passed' => $board['passed'], default => $board['to_review'] };
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
        <x-slot name="heading">Local organisations that could link to you</x-slot>
        <x-slot name="description">
            Sponsorships, memberships, dealer listings and job boards near your offices, plus local sites that already link to nearby companies.
            Mark the ones you are interested in and we reach out on your behalf — one personal email at a time, from your own address, never more than {{ $board['outreach']['cap'] }} a month. Nothing is sent to anyone you have not marked Interested.
            A link from a local organisation is one of the signals search engines read for who is established locally; some of these also carry a fee, which is yours to weigh.
        </x-slot>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
            <div><div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $t['to_review'] }}</div><div class="text-xs text-gray-500 dark:text-gray-400">To review</div></div>
            <div><div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $t['interested'] }}</div><div class="text-xs text-gray-500 dark:text-gray-400">Interested</div></div>
            <div><div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $t['contacted'] }}</div><div class="text-xs text-gray-500 dark:text-gray-400">Written to</div></div>
            <div><div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $t['won'] }}</div><div class="text-xs text-gray-500 dark:text-gray-400">Linking to you</div></div>
            <div><div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $board['outreach']['used'] }}<span class="text-sm font-normal text-gray-500"> / {{ $board['outreach']['cap'] }}</span></div><div class="text-xs text-gray-500 dark:text-gray-400">Emails this month</div></div>
        </div>

        <x-slot name="footerActions">
            @foreach (['review' => 'To review ('.$t['to_review'].')', 'interested' => 'Interested ('.$t['interested'].')', 'passed' => 'Passed ('.$t['passed'].')'] as $key => $label)
                <x-filament::button size="sm" :color="$this->show === $key ? 'primary' : 'gray'" wire:click="setShow('{{ $key }}')">{{ $label }}</x-filament::button>
            @endforeach
        </x-slot>
    </x-filament::section>

    <x-filament::section>
        @forelse ($list as $r)
            @if ($loop->first || $list[$loop->index - 1]['office_key'] !== $r['office_key'])
                <h3 class="{{ $loop->first ? '' : 'mt-6 ' }}text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $r['office_key'] === \App\Links\OfficeAttribution::CORPORATE ? $r['office'] : ($r['office_key'] === \App\Links\OfficeAttribution::COMPETITORS ? 'Sites that link to companies near you' : $r['office'].' office') }}</h3>
            @endif
            <div class="flex flex-col gap-2 border-b border-gray-100 py-4 last:border-0 sm:flex-row sm:items-start sm:justify-between dark:border-white/10">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="font-semibold text-gray-950 underline-offset-2 hover:underline dark:text-white">{{ $r['domain'] }}</a>
                        <x-filament::badge color="gray">{{ $r['kind_label'] }}</x-filament::badge>
                        @if ($r['near'] !== null)<span class="text-xs text-gray-500 dark:text-gray-400">near {{ $r['near'] }}</span>@endif
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $r['what'] }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $r['status_text'] }}@if ($r['decided_at'] !== null) · decided {{ $r['decided_at']->toFormattedDateString() }}@endif</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    @if ($r['decision'] === null && $r['open'])
                        <x-filament::button size="sm" icon="heroicon-m-hand-thumb-up" wire:click="interested('{{ $r['id'] }}')">Interested</x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="pass('{{ $r['id'] }}')">Pass</x-filament::button>
                    @elseif ($r['open'] && $r['emails_sent'] === 0)
                        <x-filament::button size="sm" color="gray" wire:click="undo('{{ $r['id'] }}')">Change my mind</x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($this->show === 'review') Nothing waiting for you — we will add opportunities here as we find them.
                @elseif ($this->show === 'interested') Nothing marked Interested yet.
                @else Nothing passed. @endif
            </p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
