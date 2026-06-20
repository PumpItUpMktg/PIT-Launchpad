@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $inv = $this->inventory;
    $counts = $inv['counts'];
    $spineColors = ['#0A4F4F', '#0E6B6B', '#4E9A98', '#A6CFCD'];
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Inventory->eyebrow() }}</div>
    <h1 class="lp-h1">Your page inventory</h1>
    <p class="lp-lede">Blueprint confirmed. Here's every page generation will build — your foundation pages, kept separate from the service and location pages your blueprint locked.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-invsum">
            <div class="iv"><span class="ivn">{{ $counts['total'] }}</span><span class="ivl">pages to build now</span></div>
            <div class="iv"><span class="ivn">{{ $counts['foundation'] }}</span><span class="ivl">foundation</span></div>
            <div class="iv"><span class="ivn">{{ $counts['service'] }}</span><span class="ivl">service</span></div>
            <div class="iv"><span class="ivn">{{ $counts['location_now'] }}</span><span class="ivl">location</span></div>
            <div class="iv"><span class="ivn">{{ $counts['reserve'] }}</span><span class="ivl">towns in reserve</span></div>
        </div>

        {{-- Foundation — the standard layer, kept separate. Optional pages toggle into the build. --}}
        <div class="lp-basic">
            <div class="bhd"><span class="bd"></span>Foundation — the basic pages every site needs</div>
            <div class="hint" style="margin:-6px 0 12px;color:var(--ink-soft)">Core pages are always built. Toggle the optional pages to include or remove them.</div>
            <div class="lp-basicgrid">
                @foreach ($inv['foundation'] as $page)
                    @if ($page['toggleable'])
                        <div wire:key="found-{{ $page['type'] }}"
                             class="lp-pageitem {{ $page['accepted'] ? 'opt on' : 'off' }}"
                             style="cursor:pointer;user-select:none"
                             title="{{ $page['accepted'] ? 'Included — click to remove' : 'Click to include' }}"
                             wire:click="toggleStandard('{{ $page['type'] }}')">
                            <span class="pd">{{ $page['accepted'] ? '✓' : '' }}</span>{{ $page['label'] }}
                        </div>
                    @else
                        <div wire:key="found-{{ $page['type'] }}" class="lp-pageitem on {{ $page['kind'] === 'legal' ? 'legal' : '' }}" title="Always built">
                            <span class="pd">✓</span>{{ $page['label'] }}
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- Directed coverage · service --}}
        <div class="lp-invlabel">Directed coverage · service pages</div>
        @forelse ($inv['silos'] as $silo)
            @php $color = $spineColors[$loop->index % count($spineColors)]; @endphp
            <div class="lp-silo">
                <div class="lp-silohd">
                    <span class="spine" style="background:{{ $color }}"></span>
                    <div><div class="nm">{{ $silo['name'] }}</div><div class="meta">{{ $silo['count'] }} {{ \Illuminate\Support\Str::plural('page', $silo['count']) }}</div></div>
                </div>
                <div class="lp-rows">
                    @include('filament.guided._inventory-row', ['row' => $silo['hub'], 'child' => false])
                    @foreach ($silo['pages'] as $page)
                        @include('filament.guided._inventory-row', ['row' => $page, 'child' => false])
                    @endforeach
                    @foreach ($silo['subhubs'] as $sub)
                        @include('filament.guided._inventory-row', ['row' => $sub, 'child' => false])
                        @foreach ($sub['pages'] as $page)
                            @include('filament.guided._inventory-row', ['row' => $page, 'child' => true])
                        @endforeach
                    @endforeach
                </div>
            </div>
        @empty
            <div class="lp-card"><div class="lp-empty">No service pages yet — finalize your structure first.</div></div>
        @endforelse

        {{-- Directed coverage · location --}}
        <div class="lp-invlabel">Directed coverage · location pages</div>
        <div class="lp-card">
            @forelse ($inv['tiers'] as $tier)
                <div class="lp-loctier">
                    <span class="ltn"><span class="lp-swatch" style="background:var(--teal-mid)"></span>{{ $tier['label'] }}</span>
                    <span class="lts">{{ collect($tier['towns'])->join(' · ') }}</span>
                </div>
            @empty
                <div class="lp-empty" style="padding:18px">No town pages selected yet — add them in Territory.</div>
            @endforelse
            @if ($counts['reserve'] > 0)
                <div class="lp-loctier"><span class="ltn"><span class="lp-swatch" style="background:var(--teal-light)"></span>Reserve</span><span class="ltr">{{ $counts['reserve'] }} towns build automatically as you earn local relevance</span></div>
            @endif
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Filament\Pages\Guided\Structure::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed">Continue to approve</button>
            <span class="lp-gate ok">{{ $counts['total'] }} pages now · {{ $counts['reserve'] }} in reserve</span>
        </div>
    @endunless
</x-guided.shell>
