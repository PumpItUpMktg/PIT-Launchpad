@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Territory->eyebrow() }}</div>
    <h1 class="lp-h1">Where do you work?</h1>
    <p class="lp-lede">We found your home county from your address and suggested the area around it. Confirm the counties you serve and the towns worth a page.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Service area</h3>
            <div class="hint">The county select + 4-tier town picker (wrapping the existing locations layer) lands in the next layer.</div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Business->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed">Build my structure</button>
            <span class="lp-gate ok">Saving advances you to Structure</span>
        </div>
    @endunless
</x-guided.shell>
