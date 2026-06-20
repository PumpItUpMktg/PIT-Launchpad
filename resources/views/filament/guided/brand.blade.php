@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; $branding = $this->branding; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Brand->eyebrow() }}</div>
    <h1 class="lp-h1">Your brand</h1>
    <p class="lp-lede">We'll generate a palette and typography for your trade, then push it straight into your site's Elementor kit.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Brand kit</h3>
            <div class="hint">Generate a brand, review it, then push it to WordPress.</div>

            @if ($branding)
                <div class="lp-chips" style="margin-bottom:14px">
                    @foreach ($branding['palette'] as $name => $hex)
                        <span class="lp-chip" style="background:#fff"><span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:{{ $hex }};border:1px solid var(--line)"></span>{{ $name }} {{ $hex }}</span>
                    @endforeach
                </div>
                @if (! empty($branding['typography']))
                    <div class="hint" style="margin:0 0 12px">Type: {{ collect($branding['typography'])->join(' · ') }}</div>
                @endif
            @endif

            <div style="display:flex;gap:8px">
                <button class="lp-mini" wire:click="generate">{{ $branding ? 'Regenerate' : 'Generate brand' }}</button>
                <button class="lp-mini primary" wire:click="pushBrand" @disabled(! $branding)>Push to WordPress</button>
            </div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::ConnectWordpress->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed" @disabled(! $this->pushed)>Continue to territory</button>
            @if ($this->pushed)
                <span class="lp-gate ok">Brand pushed to your site</span>
            @else
                <span class="lp-gate">Push your brand kit to continue</span>
            @endif
        </div>
    @endunless
</x-guided.shell>
