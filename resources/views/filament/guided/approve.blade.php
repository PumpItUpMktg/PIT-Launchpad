@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $plan = $this->sitePlan;
    $hasService = ! empty($plan['service']);
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">Step 4 of 4 · What the client approves</div>
    <h1 class="lp-h1">Here's your site plan</h1>
    <p class="lp-lede">Your whole site, in plain language — standard pages, your services, and your towns. Approve it and we'll start building.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        {{-- Fixed standard pages — always built --}}
        <div class="lp-card">
            <h3>Core pages</h3>
            <div class="hint">Every site gets these — built automatically.</div>
            <div class="lp-chips">
                @foreach ($plan['fixed'] as $f)
                    <span class="lp-chip">{{ $f['label'] }}</span>
                @endforeach
            </div>
        </div>

        {{-- Optional standard pages — accept/decline where data is available --}}
        @if (! empty($plan['optionals']))
            <div class="lp-card">
                <h3>Add these pages?</h3>
                <div class="hint">Available because you have the content for them. Toggle the ones you want.</div>
                @foreach ($plan['optionals'] as $opt)
                    <div class="lp-sug {{ $opt['accepted'] ? 'on' : '' }}" wire:click="toggleStandard('{{ $opt['type'] }}')">
                        <div class="box">{{ $opt['accepted'] ? '✓' : '' }}</div>
                        <div><div class="nm">{{ $opt['label'] }}</div><div class="why">{{ $opt['source'] }}</div></div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Service pages — from the finalized structure --}}
        @foreach ($plan['service'] as $silo)
            @php
                $others = collect($silo['pages'])->where('kind', '!=', 'hub')->pluck('name');
                $covers = collect($silo['pages'])->flatMap(fn ($p) => collect($p['sections'])->pluck('name'))->take(6);
            @endphp
            <div class="lp-plan">
                <div class="ptit">{{ $silo['name'] }}</div>
                <div class="ppages">{{ $others->isNotEmpty() ? 'Main page + '.$others->join(', ', ' and ') : 'Main page' }}</div>
                @if ($covers->isNotEmpty())<div class="pcov">Also covers: {{ $covers->join(', ') }}</div>@endif
            </div>
        @endforeach

        {{-- Location pages --}}
        @php
            $locCount = $plan['locations']['count'];
            $locSample = collect($plan['locations']['sample'])->join(', ');
            $locMore = $locCount > count($plan['locations']['sample']) ? ', and more' : '';
        @endphp
        <div class="lp-plan">
            <div class="ptit">Local pages</div>
            @if ($locCount > 0)
                <div class="ppages">{{ $locCount }} town {{ \Illuminate\Support\Str::plural('page', $locCount) }} — {{ $locSample.$locMore }}</div>
            @else
                <div class="pcov">Town pages build as you select towns and complete jobs there.</div>
            @endif
        </div>

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
            <button class="lp-btn" wire:click="approveAndBuild" @disabled(! $hasService)>Approve &amp; build</button>
            @if ($hasService)
                <span class="lp-gate ok">Ready to go live</span>
            @else
                <span class="lp-gate">Finalize your structure first</span>
            @endif
        </div>
    @endunless
</x-guided.shell>
