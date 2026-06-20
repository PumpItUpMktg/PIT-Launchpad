@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand" :grow="true">
    <div class="lp-eyebrow">Grow · {{ $brand }}</div>
    <h1 class="lp-h1">Your site is building</h1>
    <p class="lp-lede">Core pages are live. Town pages release on pace, and earn their way up as jobs and reviews come in.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet.</div></div>
    @else
        <div class="lp-card">
            <h3>Grow dashboard</h3>
            <div class="hint">Build/drip stats, the town queue, the fresh-content feed, and the re-run controls land in the next layer.</div>
        </div>
    @endunless
</x-guided.shell>
