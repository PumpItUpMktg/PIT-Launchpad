<x-filament-panels::page>
    {{-- The workspace body is shared with the guided WhereYouWork step (partials/locations/*);
         this page adds the operator's cross-tenant site picker around it. --}}
    @include('filament.pages.partials.locations.styles')

    @php
        $tierMeta = [
            'major' => ['label' => 'Major', 'color' => '#0A4F4F'],
            'large' => ['label' => 'Large', 'color' => '#0E6B6B'],
            'medium' => ['label' => 'Medium', 'color' => '#4E9A98'],
            'small' => ['label' => 'Small', 'color' => '#A6CFCD'],
            'ungrouped' => ['label' => 'Ungrouped', 'color' => '#C3CCD6'],
        ];
    @endphp

    <div class="lp-wrap">
        <div class="lp-card">
            <p class="lp-muted" style="margin:0 0 14px">Tell us where each location is and which counties you serve — then pick the towns you want location pages for.</p>
            <label>
                <span class="lp-label">Site</span>
                <select wire:model.live="siteId" class="lp-select">
                    <option value="">Select a site…</option>
                    @foreach ($this->siteOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if ($this->geocoderWarning)
            <div class="lp-warn">⚠ {{ $this->geocoderWarning }}</div>
        @endif

        @if ($siteId)
            @php
                $locations = $this->locations;
                $colors = $this->colors;
                $locating = $locations->contains(fn ($l) => $l->lat === null && ! $l->geocode_failed);
                $vm = $this->panels;
                $totals = $vm['totals'];
                $tierTotals = $totals['tiers'] ?? [];
                $activeLoc = $locations->firstWhere('id', $activeTab) ?? $locations->first();
                $activePanel = $activeLoc ? ($vm['panels'][$activeLoc->id] ?? null) : null;
                $overlap = $totals['overlap'] ?? 0;
            @endphp

            @include('filament.pages.partials.locations.hero')
            @include('filament.pages.partials.locations.tabs')

            @if ($adding)
                @include('filament.pages.partials.locations.add-panel')
            @elseif ($activeLoc)
                @include('filament.pages.partials.locations.panel')
            @elseif ($locations->isEmpty())
                <div class="lp-card lp-empty">No locations yet — add the first one.</div>
            @endif

            @if (! $locations->isEmpty())
                @include('filament.pages.partials.locations.map')

                {{-- Bottom bar: live selected · covered (persisted rows — counts agree with the hero) --}}
                <div class="lp-bottom">
                    <span><b>{{ $totals['selected'] }}</b> selected · <b>{{ $totals['covered'] }}</b> covered</span>
                    <button type="button" wire:click="compute" class="lp-btn ghost" style="background:rgba(255,255,255,.16); color:#fff; border:0">Update service area</button>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
