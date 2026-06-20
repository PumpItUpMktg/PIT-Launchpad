@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">Step 3 of 4 · Operator view</div>
    <h1 class="lp-h1">Your structure is ready to review</h1>
    <p class="lp-lede">We grouped your services into categories, grounded them on real search demand, and made a few judgment calls. Resolve the flags, then finalize.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Structure</h3>
            <div class="hint">The engine chain (silo-gen → silo-volume → auto-arrange), the prune tree, and the flag cards land in the next layer. Finalize already enforces the §4b flag gate.</div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Territory->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="finalize" @disabled(! $this->canFinalize)>Finalize structure</button>
            @if ($this->canFinalize)
                <span class="lp-gate ok">All set — ready to finalize</span>
            @else
                <span class="lp-gate">{{ $this->unresolvedFlagCount }} item{{ $this->unresolvedFlagCount === 1 ? '' : 's' }} need your input first</span>
            @endif
        </div>
    @endunless
</x-guided.shell>
