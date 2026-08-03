{{-- The client's monthly keyword-improvement report: month-over-month organic movement + Search
     Console reach. Honest framing (§7c) — observed movement only, never a per-action lead claim.
     Read-only; a metric shows only when its source has data. --}}
<x-filament-panels::page>
    @php
        $report = $this->report;
        $positions = $report['positions'] ?? null;
        $search = $report['search'] ?? null;
    @endphp

    <div class="flex flex-wrap items-center gap-3">
        @if (count($this->siteOptions) > 1)
            <x-filament::input.wrapper class="max-w-xs">
                <x-filament::input.select wire:model.live="siteId">
                    @foreach ($this->siteOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif

        <x-filament::input.wrapper class="max-w-xs">
            <x-filament::input.select wire:model.live="monthKey">
                @foreach ($this->monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    @if ($positions === null)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Select a site to see its report.</p>
        </x-filament::section>
    @else
        {{-- Headline movement --}}
        <x-filament::section>
            <x-slot name="heading">Search ranking movement — {{ $report['month_label'] }}</x-slot>
            <x-slot name="description">
                How your tracked keywords moved this month versus last, across
                {{ number_format($positions['summary']['tracked']) }}
                tracked {{ \Illuminate\Support\Str::plural('keyword', $positions['summary']['tracked']) }}.
            </x-slot>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($positions['summary']['improved']) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Climbed</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($positions['summary']['new']) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Newly ranking</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($positions['summary']['page1']) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Reached page one</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($positions['summary']['declined']) }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Slipped</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Search Console reach --}}
        <x-filament::section>
            <x-slot name="heading">Search visibility</x-slot>
            <x-slot name="description">Impressions and clicks from Google Search this month versus last.</x-slot>

            @if ($search['connected'])
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($search['this']['impressions']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Impressions
                            @include('filament.client.pages._delta', ['n' => $search['delta']['impressions']])
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($search['this']['clicks']) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Clicks
                            @include('filament.client.pages._delta', ['n' => $search['delta']['clicks']])
                        </div>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Search Console data is still collecting — this section fills in once Google has reported a full month.
                </p>
            @endif
        </x-filament::section>

        {{-- Top search queries (Search Console) — the complete long tail the site was found for this
             month, including geo / "near me" variants that ranked keyword tracking doesn't cover. --}}
        @php $queries = $report['queries'] ?? []; @endphp
        @if (count($queries) > 0)
            <x-filament::section collapsible>
                <x-slot name="heading">Top search queries ({{ count($queries) }})</x-slot>
                <x-slot name="description">The searches that showed your site this month, from Google Search Console — impressions, clicks, and average position.</x-slot>
                <div class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach (array_slice($queries, 0, 25) as $qr)
                        <div class="flex items-center justify-between gap-4 py-2">
                            <span class="min-w-0 truncate text-sm text-gray-950 dark:text-white">{{ $qr['query'] }}</span>
                            <div class="flex shrink-0 items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ number_format($qr['impressions']) }} impr</span>
                                <span><b class="text-gray-950 dark:text-white">{{ number_format($qr['clicks']) }}</b> clicks</span>
                                <span>avg #{{ $qr['position'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- Movement tables --}}
        @php
            $tables = [
                ['key' => 'improved', 'heading' => 'Top climbers', 'rows' => $positions['improved']],
                ['key' => 'new', 'heading' => 'Newly ranking', 'rows' => $positions['new']],
                ['key' => 'page1', 'heading' => 'Reached page one', 'rows' => $positions['page1']],
            ];
        @endphp

        @foreach ($tables as $table)
            @if (count($table['rows']) > 0)
                <x-filament::section collapsible>
                    <x-slot name="heading">{{ $table['heading'] }} ({{ count($table['rows']) }})</x-slot>
                    <div class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach (array_slice($table['rows'], 0, 25) as $row)
                            <div class="flex items-center justify-between gap-4 py-2">
                                <span class="min-w-0 truncate text-sm text-gray-950 dark:text-white">{{ $row['query'] }}</span>
                                <div class="flex shrink-0 items-center gap-2 text-xs">
                                    <span class="text-gray-400">
                                        {{ $row['from'] !== null ? '#'.$row['from'] : '—' }} →
                                        <span class="font-semibold text-gray-950 dark:text-white">{{ $row['to'] !== null ? '#'.$row['to'] : '—' }}</span>
                                    </span>
                                    @if ($row['delta'] !== null && $row['delta'] > 0)
                                        <x-filament::badge color="success" size="sm">+{{ $row['delta'] }}</x-filament::badge>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @endforeach

        <p class="text-xs text-gray-400 dark:text-gray-500">
            Rankings and search visibility are observed month over month. Positions can move for many
            reasons; this report shows what changed, not a forecast of future results.
        </p>
    @endif
</x-filament-panels::page>
