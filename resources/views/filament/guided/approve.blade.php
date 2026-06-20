@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $plan = $this->plan;
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">Step 4 of 4 · What the client approves</div>
    <h1 class="lp-h1">Here's your site plan</h1>
    <p class="lp-lede">This is your site, in plain language. Approve it and we'll start building — your biggest pages go up first, towns follow over time.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        @forelse ($plan['silos'] as $silo)
            @php
                $hub = collect($silo['pages'])->firstWhere('kind', 'hub');
                $others = collect($silo['pages'])->where('kind', '!=', 'hub')->pluck('name');
                $covers = collect($silo['pages'])->flatMap(fn ($p) => collect($p['sections'])->pluck('name'))->take(6);
            @endphp
            <div class="lp-plan">
                <div class="ptit">{{ $silo['name'] }}</div>
                <div class="ppages">{{ $others->isNotEmpty() ? 'Main page + '.$others->join(', ', ' and ') : 'Main page' }}</div>
                @if ($covers->isNotEmpty())
                    <div class="pcov">Also covers: {{ $covers->join(', ') }}</div>
                @endif
            </div>
        @empty
            <div class="lp-card"><div class="lp-empty">Finalize your structure first — then your plan appears here.</div></div>
        @endforelse

        @if (! empty($plan['silos']))
            <div class="lp-card" style="margin-top:18px">
                <h3>Before we build</h3>
                <div class="hint">Sensible defaults — adjust if you like.</div>
                <div class="lp-tog">
                    <div><div class="tnm">Local relevance data</div><div class="tsub">Flood zones, soil, water table, rainfall per town</div></div>
                    <button class="lp-switch {{ $this->localize ? '' : 'off' }}" wire:click="toggleLocalize"></button>
                </div>
                <div class="lp-tog">
                    <div><div class="tnm">Town page pace</div><div class="tsub">Release up to {{ $this->townPagePace }} new town pages per week</div></div>
                    <input type="number" min="1" class="lp-input" style="width:72px;margin-left:auto" wire:model="townPagePace">
                </div>
                <div class="lp-tog">
                    <div><div class="tnm">Fresh content</div><div class="tsub">Publish local news-driven articles into your categories</div></div>
                    <button class="lp-switch {{ $this->freshContent ? '' : 'off' }}" wire:click="toggleFreshContent"></button>
                </div>
            </div>

            <div class="lp-foot">
                <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Structure->pageClass()::getUrl() }}" wire:navigate>Back</a>
                <button class="lp-btn" wire:click="approveAndBuild">Approve &amp; build</button>
                <span class="lp-gate ok">Ready to go live</span>
            </div>
        @endif
    @endunless
</x-guided.shell>
