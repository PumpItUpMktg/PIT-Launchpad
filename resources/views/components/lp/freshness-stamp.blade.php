@props(['lastChecked' => null, 'interval' => null, 'trackingStartedAt' => null, 'noun' => 'data'])
{{-- The ONE panel-level freshness stamp: "positions as of 4 Sep", derived from a stored timestamp + a
     stored interval (never a per-surface threshold) via App\Support\FreshnessStamp. Reuses the Indexing
     board's "data through {date}" treatment so a stale panel reads the same everywhere. Semantic state
     only — a `lp-fresh--{severity}` class + `data-fresh-state`; the exact timestamp on hover. Per-STATE
     colour is deliberately deferred to the theme pass (functionality + semantic states first); only the
     neutral base uses an existing design token, so nothing here hardcodes a colour. --}}
@php
    $__stamp = \App\Support\FreshnessStamp::for($lastChecked, $interval, $trackingStartedAt, $noun);
@endphp
@once
    <style>
        .lp-fresh { font-size: 12px; color: var(--ink-soft); }
        /* State colours (fresh/late/stale/never_checked) resolve from tokens in the theme pass. */
    </style>
@endonce
<span {{ $attributes->merge(['class' => 'lp-fresh lp-fresh--'.$__stamp->severity]) }}
      data-fresh-state="{{ $__stamp->state->value }}"
      @if ($__stamp->exact()) title="{{ $__stamp->exact() }}" @endif>{{ $__stamp->line() }}</span>
