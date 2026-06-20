@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Business->eyebrow() }}</div>
    <h1 class="lp-h1">What does your business do?</h1>
    <p class="lp-lede">List the services you offer. We'll suggest a few you might have missed, then build your site around them.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Business</h3>
            <div class="hint">The basics we'll brand the site around.</div>
            <div class="lp-field"><label>Business name</label><input class="lp-input" value="{{ $brand }}" disabled></div>
            <div class="lp-field"><label>Trade</label><input class="lp-input" placeholder="e.g. Basement waterproofing &amp; sump pumps" disabled></div>
        </div>

        <div class="lp-card">
            <h3>Services you offer</h3>
            <div class="hint">Stated-services entry + the connecting-services suggester land in the next layer.</div>
            <div class="lp-chips">
                <span class="lp-chip" style="background:#FBFCFD;color:var(--ink-soft);border-style:dashed">+ add a service</span>
            </div>
        </div>

        <div class="lp-foot">
            <button class="lp-btn" wire:click="proceed">Continue to territory</button>
            <span class="lp-gate ok">Saving advances you to Territory</span>
        </div>
    @endunless
</x-guided.shell>
