@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; $stats = $this->stats; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand" :grow="true">
    <div class="lp-eyebrow">Grow · {{ $brand }}</div>
    <h1 class="lp-h1">Your site is building</h1>
    <p class="lp-lede">Core pages are live. Town pages release on pace, and earn their way up as jobs and reviews come in.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet.</div></div>
    @else
        <div class="lp-stats">
            <div class="lp-stat"><div class="sn">{{ number_format($stats['live']) }}</div><div class="sl">pages live</div></div>
            <div class="lp-stat"><div class="sn">{{ number_format($stats['building']) }}</div><div class="sl">building</div></div>
            <div class="lp-stat"><div class="sn">{{ number_format($stats['planned']) }}</div><div class="sl">pages planned</div></div>
        </div>

        <div class="lp-card">
            <h3>Town queue</h3>
            <div class="hint">Towns build when they have enough local relevance — a strong flood/soil story, or jobs &amp; reviews on the ground.</div>
            <div class="lp-empty" style="padding:22px">The town queue activates once the coverage layer and the drip controller are wired.</div>
        </div>

        <div class="lp-card">
            <h3>Fresh content</h3>
            <div class="hint">Local news, drafted into your categories and linked to the right service pages.</div>
            @forelse ($this->news as $item)
                <div class="lp-news">
                    @if ($item['silo'])<span class="silotag">{{ $item['silo'] }}</span>@endif
                    <div class="ntx"><b>{{ $item['title'] }}</b><div class="nmeta">{{ $item['status'] }}</div></div>
                </div>
            @empty
                <div class="lp-empty" style="padding:22px">No fresh-content posts yet — they appear as the news engine drafts them.</div>
            @endforelse
        </div>

        <div class="lp-foot">
            <button class="lp-btn ghost" wire:click="reGround">Re-ground volume</button>
            <button class="lp-btn ghost" wire:click="reArrange">Re-arrange structure</button>
            <span class="lp-gate ok">Your finalized decisions are preserved on any re-run</span>
        </div>
    @endunless
</x-guided.shell>
