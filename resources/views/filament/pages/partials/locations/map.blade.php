{{-- Shared coverage map (base pins + county outlines + flagged directed towns) — ONE view of everywhere
     the business works, across all locations.

     Inline SVG over App\Locations\CoverageMapSvg, drawn with the same projector as every other town map
     in the admin, so a county lands on the same spot here as on Town Rank and Service Areas. It replaced
     Leaflet over CARTO tiles, which now demand an API key and stamp "API KEY REQUIRED" across the
     picture. No script, so nothing to guard and nothing to break Livewire: a coverage change re-renders
     the component and the map with it. --}}
@php($cov = $this->coverageSvg)
<div class="lp-card" style="padding:8px">
    @if ($cov === null)
        <div class="lp-cov-empty">Add a location and tick the counties it serves — the map draws once there is coverage to show.</div>
    @else
        <svg class="lp-cov-map" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet"
             role="img" aria-label="Coverage map: served counties, base locations and added towns">
            @foreach ($cov['counties'] as $county)
                @foreach ($county['paths'] as $d)
                    <path d="{{ $d }}" class="lp-cov-county">
                        @if ($county['name'] !== '')<title>{{ $county['name'] }} County</title>@endif
                    </path>
                @endforeach
            @endforeach

            @foreach ($cov['pins'] as $pin)
                <circle cx="{{ $pin['x'] }}" cy="{{ $pin['y'] }}" r="1.6" class="lp-cov-pin"
                        style="fill:{{ $pin['color'] }};stroke:{{ $pin['color'] }}">
                    @if ($pin['name'] !== '')<title>{{ $pin['name'] }}</title>@endif
                </circle>
            @endforeach

            {{-- A town added by hand, drawn as a flag rather than a dot so it reads as an override. --}}
            @foreach ($cov['flags'] as $flag)
                <g class="lp-cov-flag">
                    <path d="M{{ $flag['x'] }} {{ $flag['y'] }} l0 -4.2" />
                    <path d="M{{ $flag['x'] }} {{ $flag['y'] - 4.2 }} l3 1.2 -3 1.2 z" />
                    @if ($flag['name'] !== '')<title>{{ $flag['name'] }} (added)</title>@endif
                </g>
            @endforeach
        </svg>
    @endif
</div>

<style>
    /* Deliberately NOT .lp-map: that class carries a fixed 380px height from the locations stylesheet,
       which would crop or stretch a figure that is sized by its own viewBox. */
    .lp-cov-map { display:block; width:100%; height:auto; background:#f8fafc; border-radius:10px; }
    .dark .lp-cov-map { background:#0b1017; }
    .lp-cov-empty { display:flex; align-items:center; justify-content:center; text-align:center; padding:28px 16px;
        font-size:12.5px; color:#64748b; background:#f8fafc; border-radius:10px; }
    .dark .lp-cov-empty { background:#0b1017; color:#94a3b8; }
    .lp-cov-county { fill:rgba(14,107,107,.07); stroke:#0E6B6B; stroke-width:.35; stroke-linejoin:round; }
    .lp-cov-pin { stroke-width:.4; }
    .lp-cov-flag path { fill:#b45309; stroke:#b45309; stroke-width:.35; }
</style>
