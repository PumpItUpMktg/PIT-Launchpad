@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $t = $this->territory;
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Territory->eyebrow() }}</div>
    <h1 class="lp-h1">Where do you work?</h1>
    <p class="lp-lede">We found your home county from your address and suggested the area around it. Confirm the counties you serve and the towns worth a page.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Service area</h3>
            <div class="hint">Suggested from your address, then confirmed. The detailed picker has the county pre-fill and the 4-tier town selection.</div>
            <div class="lp-chips">
                @if ($t['home'])
                    <span class="lp-chip home">Home county <span class="x">{{ $t['home'] }}</span></span>
                @endif
                <span class="lp-chip">{{ $t['counties'] }} {{ \Illuminate\Support\Str::plural('county', $t['counties']) }} served</span>
            </div>
            <div style="margin-top:14px">
                <a class="lp-mini primary" href="{{ \App\Filament\Pages\LocationsSetup::getUrl() }}" wire:navigate>
                    {{ $t['has_location'] ? 'Edit service area & towns' : 'Set up service area & towns' }}
                </a>
            </div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Brand->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed">Build my structure</button>
            <span class="lp-gate ok">Saving advances you to Structure</span>
        </div>
    @endunless
</x-guided.shell>
