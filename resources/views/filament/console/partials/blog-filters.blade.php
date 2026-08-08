{{-- Blog-page filter bar: the site switcher (ConsoleContext-scoped) plus a silo filter. Included by the
     console blog pages; the silo filter drives BlogBoard's matched_silo_id scoping. --}}
@php $siteOptions = $this->siteOptions; $siloOptions = $this->siloFilterOptions; @endphp
<style>
    .bf-bar { display:flex; align-items:center; gap:14px; margin-bottom:14px; flex-wrap:wrap; }
    .bf-group { display:flex; align-items:center; gap:8px; }
    .bf-label { font-size:12px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
    .bf-select { font-size:13.5px; padding:6px 11px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
    .bf-chip { font-size:13.5px; font-weight:650; padding:6px 12px; border-radius:8px; background:rgba(99,102,241,.1); color:#4f46e5; }
    .bf-none { font-size:13px; color:#94a3b8; }
</style>
<div class="bf-bar">
    <div class="bf-group">
        <span class="bf-label">Site</span>
        @if (count($siteOptions) === 0)
            <span class="bf-none">No sites assigned to you yet.</span>
        @elseif (count($siteOptions) === 1)
            <span class="bf-chip">{{ reset($siteOptions) }}</span>
        @else
            <select class="bf-select" wire:model.live="siteId">
                @foreach ($siteOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if (count($siloOptions) > 0)
        <div class="bf-group">
            <span class="bf-label">Silo</span>
            <select class="bf-select" wire:model.live="siloId">
                <option value="">All silos</option>
                @foreach ($siloOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($this->supportsScoreFilter())
        <div class="bf-group">
            <span class="bf-label">Score</span>
            <select class="bf-select" wire:model.live="scoreBand">
                <option value="">All scores</option>
                <option value="high">75+</option>
                <option value="mid">50–75</option>
                <option value="low">Under 50</option>
            </select>
        </div>
    @endif

    @if ($this->supportsTownFilter())
        @php $counties = $this->storefrontCounties; @endphp
        @if (count($counties) > 0)
            <div class="bf-group">
                <span class="bf-label">Area</span>
                <select class="bf-select" wire:model.live="county">
                    <option value="">All counties</option>
                    @foreach ($counties as $c)
                        <option value="{{ $c['geoid'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
                @php $selectedTowns = collect($counties)->firstWhere('geoid', $county)['towns'] ?? []; @endphp
                @if ($county && count($selectedTowns) > 0)
                    <select class="bf-select" wire:model.live="town">
                        <option value="">All towns in county</option>
                        @foreach ($selectedTowns as $t)
                            <option value="{{ $t['key'] }}">{{ $t['display'] }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endif
    @endif
</div>
