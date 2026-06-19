{{-- One spoke row (lp-): name + (connecting) note, density bar scaled to the silo max + volume,
     tag badge, and the outcome / re-tag / granularity controls (styled forms of the same bindings). --}}
@php
    $decided = ($this->spokeDecisions[$row->id]['outcome'] ?? '') !== '';
    $vol = $row->volume === null ? null : (int) $row->volume;
    $pct = $row->isPillar ? 100 : ($vol !== null && $maxVol > 0 ? min(100, ($vol / $maxVol) * 100) : 0);
    $tm = $tagMeta[$row->tag->value] ?? ['label' => ucfirst($row->tag->value), 'color' => '#94a3b8'];
    $volLabel = $vol === null ? '—' : number_format($vol);
@endphp
<div @class(['lp-spoke', 'pending' => ! $decided])>
    <div class="lp-spoke-main">
        <div class="lp-spoke-name">{{ $row->name }}</div>
        @if ($row->connectionNote)
            <div class="lp-spoke-note">↳ {{ $row->connectionNote }}</div>
        @endif
    </div>
    <div class="lp-density">
        <div class="lp-density-track"><span style="width: {{ $pct }}%; background: var(--spine)"></span></div>
        <span class="lp-vol">{{ $volLabel }}</span>
    </div>
    <span class="lp-badge" style="--bg: {{ $tm['color'] }}">{{ $tm['label'] }}</span>
    <div class="lp-controls">
        <select wire:model="spokeDecisions.{{ $row->id }}.outcome" @class(['lp-select', 'pending' => ! $decided]) style="width:148px">
            <option value="">— not decided —</option>
            @foreach ($this->outcomeOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model="spokeDecisions.{{ $row->id }}.tag" class="lp-select" title="re-tag" style="width:118px">
            @foreach ($this->tagOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model="spokeDecisions.{{ $row->id }}.granularity" class="lp-select" title="granularity" style="width:118px">
            @foreach ($this->granularityOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
