@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::WhereYouWork->eyebrow() }}</div>
    <h1 class="lp-h1">Where you work</h1>
    <p class="lp-lede">Add each physical location, then pick the counties and towns it serves. Every town you select becomes a page on your website.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        @include('filament.pages.partials.locations.styles')

        @php
            $tierMeta = [
                'major' => ['label' => 'Major', 'color' => '#0A4F4F'],
                'large' => ['label' => 'Large', 'color' => '#0E6B6B'],
                'medium' => ['label' => 'Medium', 'color' => '#4E9A98'],
                'small' => ['label' => 'Small', 'color' => '#A6CFCD'],
                'ungrouped' => ['label' => 'Ungrouped', 'color' => '#C3CCD6'],
            ];
            $locations = $this->locations;
            $colors = $this->colors;
            $locating = $locations->contains(fn ($l) => $l->lat === null && ! $l->geocode_failed);
            $vm = $this->panels;
            $totals = $vm['totals'];
            $tierTotals = $totals['tiers'] ?? [];
            $activeLoc = $locations->firstWhere('id', $activeTab) ?? $locations->first();
            $activePanel = $activeLoc ? ($vm['panels'][$activeLoc->id] ?? null) : null;
            $overlap = $totals['overlap'] ?? 0;
            $countyCount = $locations->flatMap(fn ($l) => is_array($l->county_geoids) ? $l->county_geoids : [])->unique()->count();
        @endphp

        {{-- The whole-footprint summary: locations · counties · towns · pages, at a glance --}}
        <div class="lp-invsum">
            <div class="iv"><span class="ivn">{{ $locations->count() }}</span><span class="ivl">{{ \Illuminate\Support\Str::plural('location', $locations->count()) }}</span></div>
            <div class="iv"><span class="ivn">{{ $countyCount }}</span><span class="ivl">counties served</span></div>
            <div class="iv"><span class="ivn">{{ $totals['covered'] }}</span><span class="ivl">towns covered</span></div>
            <div class="iv"><span class="ivn">{{ $totals['selected'] }}</span><span class="ivl">selected for pages</span></div>
        </div>

        @if ($this->geocoderWarning)
            <div class="lp-wrap" style="margin-bottom:16px"><div class="lp-warn">⚠ {{ $this->geocoderWarning }}</div></div>
        @endif

        <div class="lp-wrap">
            {{-- ONE view of the whole coverage area — both pins, every county, every town page --}}
            @if (! $locations->isEmpty())
                @include('filament.pages.partials.locations.map')
                @include('filament.pages.partials.locations.hero')
            @endif

            {{-- Location leads; its territory (counties + towns) nests beneath it --}}
            @include('filament.pages.partials.locations.tabs')

            @if ($adding)
                @include('filament.pages.partials.locations.add-panel')
            @elseif ($activeLoc)
                @include('filament.pages.partials.locations.panel')
            @elseif ($locations->isEmpty())
                <div class="lp-card lp-empty">No locations yet — add the first one to map your service area.</div>
            @endif
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Brand->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed">Continue to your website plan</button>
            @if ($totals['selected'] > 0)
                <span class="lp-gate ok">{{ $totals['selected'] }} town {{ \Illuminate\Support\Str::plural('page', $totals['selected']) }} selected · saved automatically</span>
            @else
                <span class="lp-gate">Add a location and tick the counties it serves</span>
            @endif
        </div>
    @endunless
</x-guided.shell>
