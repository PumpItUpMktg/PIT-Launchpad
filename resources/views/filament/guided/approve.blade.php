@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">Step 4 of 4 · What the client approves</div>
    <h1 class="lp-h1">Here's your site plan</h1>
    <p class="lp-lede">This is your site, in plain language. Approve it and we'll start building — your biggest pages go up first, towns follow over time.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Site plan</h3>
            <div class="hint">The plain-language plan (rendered from the finalized structure) + the build-config toggles land in the next layer.</div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Structure->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="approveAndBuild">Approve &amp; build</button>
            <span class="lp-gate ok">Ready to go live</span>
        </div>
    @endunless
</x-guided.shell>
