@props(['for' => null, 'tone' => null, 'label' => null])
{{-- The ONE state chip. Pass a known state (`:for="$content->status"` — a ContentStatus / SiteStatus
     / ReviewFlag / raw string) and it resolves label + tone through App\Support\Ui\StateChip, or pass
     an explicit `tone` + label/slot for a one-off. There is no per-page color choice — the five tones
     live in App\Support\Ui\ChipTone. Styles are self-contained (@once) so the chip is portable to any
     surface, inside the lp- board pages or a plain Filament view. --}}
@php
    $__tone = null;
    $__label = $label;
    if ($for !== null) {
        $__r = \App\Support\Ui\StateChip::resolve($for);
        $__tone = $__r['tone'];
        $__label ??= $__r['label'];
    } else {
        $__tone = $tone instanceof \App\Support\Ui\ChipTone
            ? $tone
            : (\App\Support\Ui\ChipTone::tryFrom((string) $tone) ?? \App\Support\Ui\ChipTone::Neutral);
    }
@endphp
@once
    <style>
        .lp-chip{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;line-height:1;letter-spacing:.04em;text-transform:uppercase;padding:4px 9px;border-radius:20px;white-space:nowrap}
        .lp-chip--good{background:#E2F0EC;color:#2E7D6B}
        .lp-chip--warn{background:#FBEFD9;color:#B5731A}
        .lp-chip--bad{background:#FCE7E2;color:#B5341A}
        .lp-chip--info{background:#E6EEF4;color:#2B5C7A}
        .lp-chip--neutral{background:#EDF0F3;color:#54666C}
        @media (prefers-color-scheme: dark){
            .lp-chip--good{background:rgba(46,125,107,.22);color:#7fd3bd}
            .lp-chip--warn{background:rgba(181,115,26,.22);color:#e6b877}
            .lp-chip--bad{background:rgba(181,52,26,.22);color:#f0a08e}
            .lp-chip--info{background:rgba(43,92,122,.28);color:#8fbdda}
            .lp-chip--neutral{background:rgba(84,102,108,.25);color:#b6c2c8}
        }
    </style>
@endonce
<span {{ $attributes->merge(['class' => 'lp-chip '.$__tone->cssClass()]) }}>{{ $__label ?? $slot }}</span>
