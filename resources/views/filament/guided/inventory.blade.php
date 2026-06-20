@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $inv = $this->inventory;
    $counts = $inv['counts'];
    $spineColors = ['#0A4F4F', '#0E6B6B', '#4E9A98', '#A6CFCD'];
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    @php $reserveTxt = $counts['reserve'] > 0 ? ' · '.$counts['reserve'].' in reserve' : ''; @endphp
    <div class="lp-eyebrow">Blueprint confirmed</div>
    <h1 class="lp-h1">Page inventory · {{ $counts['total'] }} {{ \Illuminate\Support\Str::plural('page', $counts['total']) }} from your blueprint</h1>
    <p class="lp-lede">{{ $counts['service'] }} service · {{ $counts['location_now'] }} location now{{ $reserveTxt }}. This is exactly what generation will build.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        {{-- Service pages, grouped by silo --}}
        @foreach ($inv['silos'] as $silo)
            @php $color = $spineColors[$loop->index % count($spineColors)]; @endphp
            <div class="lp-silo">
                <div class="lp-silohd">
                    <span class="spine" style="background:{{ $color }}"></span>
                    <div><div class="nm">{{ $silo['name'] }}</div><div class="meta">Category hub · {{ $silo['count'] }} {{ \Illuminate\Support\Str::plural('page', $silo['count']) }}</div></div>
                </div>
                <div class="lp-rows">
                    {{-- hub --}}
                    @include('filament.guided._inventory-row', ['row' => $silo['hub'], 'child' => false])
                    {{-- own-page cores --}}
                    @foreach ($silo['pages'] as $page)
                        @include('filament.guided._inventory-row', ['row' => $page, 'child' => false])
                    @endforeach
                    {{-- sub-hubs with their pages beneath --}}
                    @foreach ($silo['subhubs'] as $sub)
                        @include('filament.guided._inventory-row', ['row' => $sub, 'child' => false])
                        @foreach ($sub['pages'] as $page)
                            @include('filament.guided._inventory-row', ['row' => $page, 'child' => true])
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Location pages --}}
        <div class="lp-card">
            <h3>Location pages</h3>
            <div class="hint">Town pages to build now. The rest stay in reserve and earn a page as they gain local relevance.</div>
            @forelse ($inv['tiers'] as $tier)
                <div class="lp-tier">
                    <div class="lp-tierhd"><span class="lp-swatch" style="background:var(--teal-mid)"></span><span class="lp-tiernm">{{ strtoupper($tier['label']) }}</span><span class="lp-tiercount">— {{ count($tier['towns']) }}</span></div>
                    <div class="lp-townrow">
                        @foreach ($tier['towns'] as $town)
                            <span class="lp-town sel">{{ $town }} <span class="pg">●</span></span>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="lp-empty" style="padding:18px">No town pages selected yet — add them in Territory.</div>
            @endforelse
            @if ($counts['reserve'] > 0)
                <div class="lp-townrow" style="margin-top:8px"><span class="lp-town" style="color:var(--ungrouped)">+{{ $counts['reserve'] }} towns build as they earn relevance</span></div>
            @endif
        </div>

        {{-- Standard note --}}
        <div class="lp-card" style="background:#FBFCFD">
            <div class="hint" style="margin:0">Standard site pages (Home, About, Contact, Areas We Serve…) are configured and added next.</div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Filament\Pages\Guided\Structure::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed">Continue</button>
            <span class="lp-gate ok">Next: add your standard pages &amp; approve</span>
        </div>
    @endunless
</x-guided.shell>
