<x-filament-panels::page>
    @php
        $inputClass = 'fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5';
    @endphp

    @unless ($started)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                Pick a site with a candidate tree, then walk it into a confirmed blueprint: batch-confirm the stated
                core, route the volume-sorted lean-ins, fold thin silos, and quick-route the fringe. Nothing is built
                until you finalize — un-reviewed candidates are dropped.
            </p>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <label class="flex-1">
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Site</span>
                    <select wire:model.live="siteId" class="{{ $inputClass }}">
                        <option value="">Select a site…</option>
                        @foreach ($this->siteOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($this->hasCandidates)
                    <x-filament::button wire:click="start" icon="heroicon-m-scissors">Open prune</x-filament::button>
                @endif
            </div>

            @if ($siteId && ! $this->hasCandidates)
                <div class="mt-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-300">
                    No candidate tree yet — run <code>launchpad:silo-expand --persist</code> for this site first.
                </div>
            @endif
        </div>
    @elseif ($finalized)
        <div class="rounded-xl bg-success-50 p-6 text-sm text-success-700 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300">
            Blueprint confirmed — the directed-coverage layer is locked. This is the page inventory generation builds from.
        </div>
    @else
        @php $p = $this->preview; @endphp

        {{-- Sticky action / gate summary --}}
        <div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm">
                <span class="font-semibold text-success-600 dark:text-success-400">{{ $p['built'] }} will be built</span>
                · <span class="text-gray-500">{{ $p['skipped'] }} skipped</span>
                · <span class="font-medium text-warning-600 dark:text-warning-400">{{ $p['pending'] }} pending → dropped</span>
            </div>
            <div class="flex gap-2">
                <x-filament::button wire:click="saveDraft" color="gray" icon="heroicon-m-bookmark">Save draft</x-filament::button>
                <x-filament::button wire:click="finalize" color="success" icon="heroicon-m-check-badge"
                    wire:confirm="Finalize? {{ $p['pending'] }} pending candidate(s) will be dropped (not built).">
                    Finalize
                </x-filament::button>
            </div>
        </div>

        @foreach ($this->bySilo as $silo => $rows)
            @php
                $s = $this->summaries[$silo];
                $core = array_filter($rows, fn ($r) => $r->tag->value === 'core');
                $leanIns = array_filter($rows, fn ($r) => in_array($r->tag->value, ['adjacent', 'connecting'], true));
                $foldTargets = array_values(array_diff(array_keys($this->bySilo), [$silo]));
            @endphp

            <div class="flex flex-col gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                {{-- Silo header + structure controls --}}
                <div class="flex flex-col gap-3 border-b border-gray-100 pb-3 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ $silo }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $s['total'] }} spokes — {{ $s['core'] }} core, {{ $s['lean_ins'] }} lean-ins @ {{ $s['lean_in_volume'] }} searches
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="siloDecisions.{{ $silo }}.rename" placeholder="rename…"
                            class="{{ $inputClass }} w-32" />
                        <select wire:model="siloDecisions.{{ $silo }}.fold_into" class="{{ $inputClass }} w-40">
                            <option value="">fold into…</option>
                            @foreach ($foldTargets as $target)
                                <option value="{{ $target }}">{{ $target }}</option>
                            @endforeach
                        </select>
                        <x-filament::button wire:click="confirmCore('{{ $silo }}')" size="xs" color="gray" icon="heroicon-m-check">
                            Confirm all core
                        </x-filament::button>
                    </div>
                </div>

                {{-- Core block --}}
                @if (count($core))
                    <div class="flex flex-col gap-2">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Stated core — verify</h4>
                        @foreach ($core as $row)
                            @include('filament.pages.partials.prune-row', ['row' => $row, 'inputClass' => $inputClass])
                        @endforeach
                    </div>
                @endif

                {{-- Lean-in block --}}
                @if (count($leanIns))
                    <div class="flex flex-col gap-2">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-primary-500">Lean-ins — the focus (highest volume first)</h4>
                        @foreach ($leanIns as $row)
                            @include('filament.pages.partials.prune-row', ['row' => $row, 'inputClass' => $inputClass])
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Fringe handoff --}}
        @if (count($this->fringe))
            <div class="flex flex-col gap-2 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Fringe handoff — refer out / sibling brand (no pages built)</h4>
                @foreach ($this->fringe as $row)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-700 dark:text-gray-200">{{ $row->name }}
                            @if ($row->connectionNote)<span class="text-gray-400">— {{ $row->connectionNote }}</span>@endif
                        </span>
                        <select wire:model="spokeDecisions.{{ $row->id }}.outcome" class="{{ $inputClass }} w-44">
                            <option value="">— handoff —</option>
                            <option value="skipped">Refer out / sibling brand</option>
                        </select>
                    </div>
                @endforeach
            </div>
        @endif
    @endunless
</x-filament-panels::page>
