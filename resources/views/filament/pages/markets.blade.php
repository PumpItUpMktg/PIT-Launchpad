<x-lp.shell
    variant="board"
    eyebrow="Territory"
    title="Markets"
    lede="One card per GBP-anchored market — its pages, reach and deployment at a glance. A market rolls up its own page plus every town beneath it. Click through for the market's geo grids, towns table and coverage.">

    @php($cards = $this->cards)

    <style>
        .lp-mc-wall { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:16px; }
    </style>

    @if ($this->siteId === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to see its markets.
        </x-lp.empty>
    @elseif (empty($cards))
        <x-lp.empty title="No markets yet" action="Open Setup" :href="\App\Filament\Pages\Onboarding::getUrl()">
            Markets are the tenant's GBP-anchored service areas. Add a location in Setup for this tenant first.
        </x-lp.empty>
    @else
        <div class="lp-mc-wall">
            @foreach ($cards as $card)
                <x-lp.market-card :card="$card->toArray()" />
            @endforeach
        </div>
    @endif
</x-lp.shell>
