@props(['steps' => [], 'brand' => 'your business', 'grow' => false])

{{-- The guided-flow shell: the lp- stepper rail + the step's main column, inside the Filament page. --}}
<x-filament-panels::page>
    @include('filament.guided._styles')
    <div class="lp-scope">
        <div class="lp-shell">
            @include('filament.guided._stepper', ['steps' => $steps, 'brand' => $brand, 'grow' => $grow])
            <main class="lp-main {{ $grow ? 'wide' : '' }}">
                {{ $slot }}
            </main>
        </div>
    </div>
</x-filament-panels::page>
