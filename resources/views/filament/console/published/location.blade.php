<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.published-styles')

    @php
        $storefrontsWithTowns = collect($this->board['storefronts'])->filter(fn ($s) => count($s['towns']) > 0)->values();
        // Resolve the active storefront sub-tab (default to the first with towns).
        $activeSfId = $activeStorefront ?? ($storefrontsWithTowns[0]['location_id'] ?? null);
        $activeSf = $storefrontsWithTowns->firstWhere('location_id', $activeSfId);
        $filtered = ($county ?? null) || ($siloId ?? null);
    @endphp

    @if ($storefrontsWithTowns->count() > 0)
        <div class="pt-subtabs">
            @foreach ($storefrontsWithTowns as $sf)
                <button class="pt-sub {{ $activeSfId === $sf['location_id'] ? 'on' : '' }}"
                        wire:click="$set('activeStorefront', '{{ $sf['location_id'] }}')" type="button">
                    {{ $sf['name'] }}
                    @if ($sf['is_storefront']) <span class="pt-badge">🏬</span> @endif
                    <span class="pt-n">{{ count($sf['towns']) }}</span>
                </button>
            @endforeach
        </div>

        @if ($activeSf)
            <div class="rc-cards">
                @foreach ($activeSf['towns'] as $post)
                    @include('filament.console.partials.post-card', ['post' => $post])
                @endforeach
            </div>
        @else
            <div class="rc-empty">Pick a storefront above to see its town pages.</div>
        @endif
    @else
        <div class="rc-empty">No live location (town) pages{{ $filtered ? ' for this filter' : '' }} yet.</div>
    @endif
</x-filament-panels::page>
