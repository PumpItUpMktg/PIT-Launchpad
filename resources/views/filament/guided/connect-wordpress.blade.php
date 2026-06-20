@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; $checklist = $this->checklist; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::ConnectWordpress->eyebrow() }}</div>
    <h1 class="lp-h1">Connect your WordPress site</h1>
    <p class="lp-lede">We'll connect to your live site, install the companion plugin and Elementor, and tidy up — so everything's ready before we build.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Your WordPress login</h3>
            <div class="hint">Use an application password — in WordPress under Users → Profile → Application Passwords. Not your regular login password.</div>
            <div class="lp-field"><label>Site URL</label><input class="lp-input" wire:model="baseUrl" placeholder="https://yoursite.com"></div>
            <div class="lp-field"><label>WordPress username</label><input class="lp-input" wire:model="username" placeholder="admin"></div>
            <div class="lp-field"><label>Application password</label><input class="lp-input" type="password" wire:model="appPassword" placeholder="xxxx xxxx xxxx xxxx"></div>
            <button class="lp-mini primary" wire:click="connectAndPrep">Connect &amp; prep</button>
        </div>

        @if (! empty($checklist))
            <div class="lp-card">
                <h3>Setup checklist</h3>
                <div class="hint">All four must be green before we continue.</div>
                @foreach ($checklist as $label => $done)
                    <div class="lp-tog">
                        <div><div class="tnm">{{ $label }}</div></div>
                        <span class="lp-pill" style="margin-left:auto;background:{{ $done ? 'var(--good-bg)' : '#EEF2F4' }};color:{{ $done ? 'var(--good)' : 'var(--ink-soft)' }}">{{ $done ? 'Done' : 'Pending' }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Business->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed" @disabled(! $this->ready)>Continue to brand</button>
            @if ($this->ready)
                <span class="lp-gate ok">WordPress connected &amp; prepped</span>
            @else
                <span class="lp-gate">Connect &amp; prep to continue</span>
            @endif
        </div>
    @endunless
</x-guided.shell>
