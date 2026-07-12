@props(['steps' => [], 'brand' => 'your business', 'grow' => false, 'embedded' => false])

{{-- The guided-flow shell. Two modes:
     - full-bleed (setup steps): the lp- stepper rail + main column, Filament chrome hidden — the
       rail IS the navigation for the linear setup flow.
     - embedded (Grow, the permanent workbench): no rail, normal Filament sidebar stays visible so
       Local Blog / Live / Targeting are always one click away. Same lp- board styling. --}}
<x-filament-panels::page>
    @include('filament.guided._styles')
    <div class="lp-scope {{ $embedded ? 'lp-embedded' : 'lp-full' }}">
        <div class="lp-shell">
            @unless ($embedded)
                @include('filament.guided._stepper', ['steps' => $steps, 'brand' => $brand, 'grow' => $grow])
            @endunless
            <main class="lp-main {{ $grow ? 'wide' : '' }}">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-filament-panels::page>
