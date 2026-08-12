<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.published-styles')

    @php
        $hubs = collect($this->board['storefronts'])->filter(fn ($s) => $s['hub'])->values();
        $filtered = ($county ?? null) || ($siloId ?? null);
    @endphp

    @if ($hubs->count() > 0)
        <div class="rc-cards">
            @foreach ($hubs as $sf)
                <div wire:key="sf-hub-{{ $sf['location_id'] }}">
                    <div class="rc-chips" style="margin-bottom:6px;">
                        <span class="rc-chip silo">🏬 {{ $sf['name'] }}</span>
                        @if ($sf['is_storefront']) <span class="pt-badge">Storefront</span> @endif
                        @if ($sf['gbp_linked']) <span class="pt-badge" style="background:rgba(22,163,74,.15); color:#15803d;">GBP linked</span> @endif
                    </div>
                    @include('filament.console.partials.post-card', ['post' => $sf['hub']])
                </div>
            @endforeach
        </div>
    @else
        <div class="rc-empty">No live storefront hub pages{{ $filtered ? ' for this filter' : '' }} yet.</div>
    @endif
</x-filament-panels::page>
