@props([
    'variant' => 'board',
    'title' => null,
    'eyebrow' => null,
    'lede' => null,
    'scope' => true,
])
{{-- The three canonical admin page shells — one frame so every lp- page opens the same way
     (Filament page + the shared _lp-styles + the .lpa scope + the standard page header), differing
     only by layout intent:

       • board     — a grid/stack of cards (Portfolio, per-site dashboards). The default.
       • table     — a single full-width list/table surface (queues, directories).
       • workspace — a main column + a right `aside` rail (detail/editor pages).

     Pass `title` (+ optional `eyebrow`/`lede`) to render the standard header with the site-scope
     indicator; put the one primary action in the `action` slot, title-adjacent chips in `meta`, and
     (workspace only) the rail in the `aside` slot. --}}
<x-filament-panels::page>
    @include('filament._lp-styles')
    {{-- The variant class must not contain "-tab" / "-btn" / "-pill" / "-chip": the global interaction
         sheet (filament.shared.interaction-styles) prefix-matches those to style controls, and a shell
         matched as a control makes the whole page behave as one button. "table" → "listing". --}}
    @php($__variantClass = $variant === 'table' ? 'listing' : $variant)
    <div {{ $attributes->merge(['class' => 'lpa lp-shell lp-shell--'.$__variantClass]) }}>
        @if ($title)
            <x-lp.page-header :eyebrow="$eyebrow" :title="$title" :lede="$lede" :scope="$scope">
                @isset($meta)
                    <x-slot:meta>{{ $meta }}</x-slot:meta>
                @endisset
                @isset($action)
                    <x-slot:action>{{ $action }}</x-slot:action>
                @endisset
            </x-lp.page-header>
        @endif

        @if ($variant === 'workspace')
            <div class="lp-workspace">
                <div class="lp-workspace-main">{{ $slot }}</div>
                @isset($aside)
                    <aside class="lp-workspace-aside">{{ $aside }}</aside>
                @endisset
            </div>
        @else
            {{ $slot }}
        @endif
    </div>
</x-filament-panels::page>
