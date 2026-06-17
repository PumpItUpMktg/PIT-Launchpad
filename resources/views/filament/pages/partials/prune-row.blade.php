{{-- One spoke row: name + volume + (connecting) note, with outcome / re-tag / granularity controls. --}}
@php $decided = ($this->spokeDecisions[$row->id]['outcome'] ?? '') !== ''; @endphp
<div @class([
    'flex flex-col gap-2 rounded-lg px-3 py-2 ring-1 sm:flex-row sm:items-center sm:justify-between',
    'bg-gray-50 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => $decided,
    'bg-warning-50/40 ring-warning-600/20 dark:bg-warning-500/5' => ! $decided,
])>
    <div class="min-w-0 flex-1 text-sm">
        <span class="font-medium text-gray-800 dark:text-gray-100">{{ $row->name }}</span>
        <span class="ml-2 text-xs text-gray-400">{{ $row->volume === null ? '—' : $row->volume.' searches' }}</span>
        @if ($row->connectionNote)
            <div class="text-xs text-gray-500 dark:text-gray-400">↳ {{ $row->connectionNote }}</div>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model="spokeDecisions.{{ $row->id }}.outcome" class="{{ $inputClass }} w-44">
            <option value="">— not decided —</option>
            @foreach ($this->outcomeOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model="spokeDecisions.{{ $row->id }}.tag" class="{{ $inputClass }} w-32" title="re-tag">
            @foreach ($this->tagOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model="spokeDecisions.{{ $row->id }}.granularity" class="{{ $inputClass }} w-32" title="granularity">
            @foreach ($this->granularityOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
