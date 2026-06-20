@php $site = $this->getSite(); $brand = $this->businessName ?: ($site?->brand_name ?? 'your business'); @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">Add a new site · {{ \App\Enums\SetupStep::Business->eyebrow() }}</div>
    <h1 class="lp-h1">What does your business do?</h1>
    <p class="lp-lede">List the services you offer. We'll suggest a few you might have missed, then build your site around them.</p>

    @if ($site)
        <div class="lp-basic" style="background:var(--amber-bg);border-color:#ECD6A8">
            <div class="bhd" style="color:var(--amber)"><span class="bd" style="background:var(--amber)"></span>Before the next step — have your WordPress ready</div>
            <div class="hint" style="margin:0">
                Step 2 connects to your live WordPress site. To breeze through it, have ready:
                a live WordPress site, an admin login that can install plugins, and a WordPress
                <strong>application password</strong> (create one under <strong>Users → Profile → Application Passwords</strong> — it's separate from your login password).
            </div>
        </div>
    @endif

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        <div class="lp-card">
            <h3>Business</h3>
            <div class="hint">The basics we'll brand the site around.</div>
            <div class="lp-field"><label>Business name</label><input class="lp-input" wire:model="businessName"></div>
            <div class="lp-field"><label>Trade</label><input class="lp-input" wire:model.blur="trade" wire:change="suggest" placeholder="e.g. Basement waterproofing &amp; sump pumps"></div>
        </div>

        <div class="lp-card">
            <h3>Services you offer</h3>
            <div class="hint">Add everything you do — this is what your site gets built around.</div>
            <div class="lp-chips">
                @foreach ($this->services as $i => $service)
                    <span class="lp-chip">{{ $service }} <span class="x" style="cursor:pointer" wire:click="removeService({{ $i }})">×</span></span>
                @endforeach
            </div>
            <div class="lp-field" style="margin-top:12px;display:flex;gap:8px">
                <input class="lp-input" wire:model="newService" wire:keydown.enter.prevent="addService" placeholder="Add a service">
                <button class="lp-mini primary" wire:click="addService">Add</button>
            </div>
        </div>

        <div class="lp-card">
            <h3>You may also offer these</h3>
            <div class="hint">Common for your trade. Check the ones you provide — they'll be added to your services.</div>
            @forelse ($this->suggestions as $i => $sug)
                <div class="lp-sug {{ $sug['on'] ? 'on' : '' }}" wire:click="toggleSuggestion({{ $i }})">
                    <div class="box">{{ $sug['on'] ? '✓' : '' }}</div>
                    <div><div class="nm">{{ $sug['name'] }}</div><div class="why">{{ $sug['why'] }}</div></div>
                </div>
            @empty
                <div class="hint" style="margin:0">
                    @if (trim($this->trade) === '')
                        Enter your trade above and we'll suggest services.
                    @else
                        <button class="lp-mini" wire:click="suggest">Suggest services</button>
                    @endif
                </div>
            @endforelse
        </div>

        @php $added = collect($this->suggestions)->where('on', true)->count(); @endphp
        <div class="lp-foot">
            <button class="lp-btn" wire:click="proceed">Continue to territory</button>
            <span class="lp-gate ok">{{ count($this->services) }} {{ \Illuminate\Support\Str::plural('service', count($this->services)) }}@if ($added) · {{ $added }} suggestion{{ $added === 1 ? '' : 's' }} added @endif</span>
        </div>
    @endunless
</x-guided.shell>
