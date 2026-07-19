{{-- One spoke as a grid row: Name (1fr) | Searches (~84px, mono) | Tag (~110px) | Page/disposition
     (~160px). Same template on parents and nested children — only the name cell is indented, so the
     right three columns stay aligned at any depth. The disposition / fold-target / re-tag bindings
     are unchanged (auto-applying via the updated hooks). --}}
@php
    $decision = $this->spokeDecisions[$row->id] ?? [];
    $gran = $decision['granularity'] ?? $row->granularity->value;
    $folded = $gran === 'folded';
    $blogTarget = $gran === 'blog_target';
    $pending = ($decision['outcome'] ?? '') === '';
    // The routed destination, made visible (longtail relay): a supporting keyword either folds
    // into a page or becomes an article target — one home, shown on the row.
    $foldTargetName = $foldOptions[$decision['fold_into'] ?? ''] ?? null;
    $intentMeta = match ($row->intent?->value) {
        'transactional' => ['label' => 'Transactional', 'color' => '#16a34a'],
        'commercial' => ['label' => 'Commercial', 'color' => '#d97706'],
        'informational' => ['label' => 'Informational', 'color' => '#2563eb'],
        default => null,
    };
    $vol = $row->volume === null ? null : (int) $row->volume;
    $volLabel = $vol === null ? '—' : number_format($vol);
    $tm = $tagMeta[$row->tag->value] ?? ['label' => ucfirst($row->tag->value), 'color' => '#94a3b8'];
    $indent = ($depth ?? 0) * 22;
@endphp
<div @class(['lp-row', 'pending' => $pending]) wire:key="lp-spoke-{{ $row->id }}" data-spoke-id="{{ $row->id }}">
    <div class="lp-cell-name" style="padding-left: {{ $indent }}px">
        <span class="lp-drag" title="drag to another silo to re-home">⠿</span>
        @php $dispClass = $blogTarget ? 'blog' : ($folded ? 'sec' : 'page'); $dispText = $blogTarget ? 'blog' : ($folded ? 'section' : 'page'); @endphp
        <span class="lp-disp {{ $dispClass }}" title="how this topic ships">{{ $dispText }}</span>
        <span class="lp-row-name">{{ $row->name }}</span>
        @if ($row->connectionNote)
            <div class="lp-row-note">↳ {{ $row->connectionNote }}</div>
        @endif
    </div>
    <div class="lp-cell-vol">{{ $volLabel }}</div>
    <div class="lp-cell-tag">
        <span class="lp-badge" style="--bg: {{ $tm['color'] }}">{{ $tm['label'] }}</span>
        @if ($intentMeta)
            <span class="lp-badge" style="--bg: {{ $intentMeta['color'] }}" title="search intent">{{ $intentMeta['label'] }}</span>
        @endif
    </div>
    <div class="lp-cell-page">
        <select wire:model.live="spokeDecisions.{{ $row->id }}.granularity" class="lp-select" title="disposition">
            @foreach ($this->granularityOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @if ($folded && ! empty($foldOptions))
            <select wire:model.live="spokeDecisions.{{ $row->id }}.fold_into" class="lp-select lp-foldsel" title="fold into">
                @foreach ($foldOptions as $value => $label)
                    <option value="{{ $value }}">↳ {{ $label }}</option>
                @endforeach
            </select>
        @endif
        @if ($blogTarget)
            <div class="lp-row-note" title="this keyword becomes a directed article target, not a page section">→ blog queue</div>
        @elseif ($folded && $foldTargetName)
            <div class="lp-row-note">→ folds into {{ $foldTargetName }}</div>
        @endif
    </div>
</div>
