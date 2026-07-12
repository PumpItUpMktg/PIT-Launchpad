<x-filament-panels::page>
    <div class="lv-wrap">
        @include('filament.live.partials.shell-top', ['subtitle' => 'Published hub and service pages and how they are earning. Regenerating or taking a page down moves it back to the Grow board automatically.'])

        @if ($this->cards === [])
            <div class="lv-empty">No service pages live yet — publish them from the Grow board and they appear here.</div>
        @else
            <div class="lv-grid">
                @foreach ($this->cards as $card)
                    @include('filament.live.partials.card', ['card' => $card])
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
