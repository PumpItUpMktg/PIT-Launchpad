{{-- One spoke row (lp-): name + (connecting) note, density bar scaled to the silo max + volume,
     tag badge (metadata), and the disposition (own page / fold) + fold-target + re-tag controls —
     styled forms of the same bindings. Defaults are pre-seeded; an undecided row reads pending. --}}
@php
    $decision = $this->spokeDecisions[$row->id] ?? [];
    $gran = $decision['granularity'] ?? $row->granularity->value;
    $folded = $gran === 'folded';
    $pending = ($decision['outcome'] ?? '') === '';
    $vol = $row->volume === null ? null : (int) $row->volume;
    $pct = $row->isPillar ? 100 : ($vol !== null && $maxVol > 0 ? min(100, ($vol / $maxVol) * 100) : 0);
    $tm = $tagMeta[$row->tag->value] ?? ['label' => ucfirst($row->tag->value), 'color' => '#94a3b8'];
    $volLabel = $vol === null ? '—' : number_format($vol);
@endphp
<div @class(['lp-spoke', 'pending' => $pending]) wire:key="lp-spoke-{{ $row->id }}" data-spoke-id="{{ $row->id }}">
    <div class="lp-spoke-main">
        <div class="lp-spoke-name"><span class="lp-drag" title="drag to another silo to re-home">⠿</span> {{ $row->name }}</div>
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
        {{-- disposition: own page vs fold (the existing granularity binding) --}}
        <select wire:model.live="spokeDecisions.{{ $row->id }}.granularity" class="lp-select" title="disposition" style="width:118px">
            @foreach ($this->granularityOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        {{-- fold target (when folded): which core page absorbs the longtail --}}
        @if ($folded && ! empty($foldOptions))
            <select wire:model.live="spokeDecisions.{{ $row->id }}.fold_into" class="lp-select" title="fold into" style="width:150px">
                @foreach ($foldOptions as $value => $label)
                    <option value="{{ $value }}">↳ {{ $label }}</option>
                @endforeach
            </select>
        @endif
        {{-- re-tag (metadata / rationale; preserved binding) --}}
        <select wire:model="spokeDecisions.{{ $row->id }}.tag" class="lp-select" title="re-tag" style="width:108px">
            @foreach ($this->tagOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
