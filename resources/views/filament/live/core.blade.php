<x-filament-panels::page>
    <div class="lv-wrap">
        @include('filament.live.partials.shell-top', ['subtitle' => 'Your published core pages (home, about, contact, and the rest) and how they are earning.'])

        @if ($this->cards === [])
            <div class="lv-empty">No core pages live yet — publish them from the Grow board and they appear here.</div>
        @else
            <div class="lv-grid">
                @foreach ($this->cards as $card)
                    @include('filament.live.partials.card', ['card' => $card])
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
