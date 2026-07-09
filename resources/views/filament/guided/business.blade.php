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
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="lp-field" style="margin-bottom:0">
                    <label>Phone number</label>
                    <input class="lp-input" wire:model.blur="phone" type="tel" placeholder="(973) 555-0100" autocomplete="tel">
                </div>
                <div class="lp-field" style="margin-bottom:0">
                    <label>Emergency line <span class="hint" style="font-weight:400">— optional</span></label>
                    <input class="lp-input" wire:model.blur="emergencyPhone" type="tel" placeholder="After-hours number" autocomplete="tel">
                </div>
            </div>
            @if (trim($this->phone) === '')
                <div class="hint" style="margin:8px 0 0;color:var(--amber)">
                    ⚠ Add your phone number — it's the main call-to-action in your site header, hero and CTA. Without it, visitors can't call.
                </div>
            @endif
            <div class="lp-field" style="margin:12px 0 0">
                <label>Business email <span class="hint" style="font-weight:400">— optional</span></label>
                <input class="lp-input" wire:model.blur="email" type="email" placeholder="office@yourbusiness.com" autocomplete="email">
            </div>
            <div class="lp-field" style="margin:12px 0 0">
                <label>Business address <span class="hint" style="font-weight:400">— optional</span></label>
                <input class="lp-input" wire:model.blur="address" placeholder="12 Main Street, Newark, NJ 07102" autocomplete="street-address">
                <label style="display:flex;align-items:flex-start;gap:.5rem;margin-top:6px;font-weight:400;font-size:.85rem;color:var(--ungrouped);cursor:pointer">
                    <input type="checkbox" wire:model="isStorefront" style="margin-top:.15rem">
                    <span>Customers visit this address — show it on the site with a map pin. Leave unchecked if you work at customers' locations (your address stays private).</span>
                </label>
            </div>
        </div>

        <div class="lp-card">
            <h3>Logo <span class="hint" style="font-weight:400">— optional</span></h3>
            <div class="hint">PNG or SVG works best. We'll add it to your site header and pull your brand colors from it as a style option — you can skip this.</div>
            @if ($this->logoInfo)
                <div style="display:flex;align-items:center;gap:16px;margin-top:12px;flex-wrap:wrap">
                    <img src="{{ $this->logoInfo['url'] }}" alt="Your logo" style="height:56px;width:auto;max-width:180px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px">
                    @if ($this->logoInfo['primary'])
                        <div style="display:flex;align-items:center;gap:8px">
                            <span class="hint" style="margin:0">Brand colors:</span>
                            <span title="{{ $this->logoInfo['primary'] }}" style="width:22px;height:22px;border-radius:50%;background:{{ $this->logoInfo['primary'] }};border:1px solid var(--line)"></span>
                            @if ($this->logoInfo['accent'])
                                <span title="{{ $this->logoInfo['accent'] }}" style="width:22px;height:22px;border-radius:50%;background:{{ $this->logoInfo['accent'] }};border:1px solid var(--line)"></span>
                            @endif
                            <span class="hint" style="margin:0">→ available as a style option</span>
                        </div>
                    @else
                        <span class="hint" style="margin:0">No clear brand color found — the logo is still in your header.</span>
                    @endif
                    <button class="lp-mini" wire:click="removeLogo" style="margin-left:auto">Remove</button>
                </div>
            @endif
            <div class="lp-field" style="margin-top:12px">
                <label class="lp-upload">
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/svg+xml,.svg,.png,.jpg,.jpeg">
                    <span class="ic">⬆</span>
                    <span class="tx">
                        <strong>{{ $this->logoInfo ? 'Choose a different logo' : 'Choose a logo file' }}</strong>
                        <span>PNG, JPG or SVG · up to 4&nbsp;MB</span>
                    </span>
                </label>
                <div wire:loading wire:target="logo" class="hint" style="margin:6px 0 0">Reading your logo…</div>
                @error('logo') <div class="hint" style="margin:6px 0 0;color:#b91c1c">{{ $message }}</div> @enderror
            </div>
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

        <div class="lp-card">
            <h3>Guarantee <span class="hint" style="font-weight:400">— optional</span></h3>
            <div class="hint">Your guarantee or warranty, in your words. It shows as a standout promise on your site. Only add one you actually offer.</div>
            <div class="lp-field"><label>Name</label><input class="lp-input" wire:model="guaranteeName" placeholder="e.g. Forever Pump Warranty, Satisfaction Guaranteed"></div>
            <div class="lp-field" style="margin-bottom:0"><label>Description</label><input class="lp-input" wire:model="guaranteeDescription" placeholder="One line — what the guarantee covers"></div>
        </div>

        <div class="lp-card">
            <h3>Certifications &amp; credentials <span class="hint" style="font-weight:400">— optional</span></h3>
            <div class="hint">Licenses, certifications, ratings — only what you actually hold. These are trust <em>and legal</em> claims, so we show exactly what you enter, nothing invented.</div>
            <div class="lp-chips">
                @foreach ($this->certifications as $i => $cert)
                    <span class="lp-chip">{{ $cert['label'] }}@if (!empty($cert['number'])) <span style="opacity:.6">{{ $cert['number'] }}</span>@endif <span class="x" style="cursor:pointer" wire:click="removeCertification({{ $i }})">×</span></span>
                @endforeach
            </div>
            <div class="lp-field" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <input class="lp-input" style="flex:2;min-width:180px" wire:model="newCertLabel" wire:keydown.enter.prevent="addCertification" placeholder="Credential (e.g. NJ Master Plumber, BBB A+)">
                <input class="lp-input" style="flex:1;min-width:120px" wire:model="newCertNumber" wire:keydown.enter.prevent="addCertification" placeholder="Number (optional)">
                <button class="lp-mini primary" wire:click="addCertification">Add</button>
            </div>
        </div>

        <div class="lp-card">
            <h3>Business hours <span class="hint" style="font-weight:400">— optional</span></h3>
            <div class="hint">Shown on your Contact page. Leave a day blank to skip it; connecting your Google Business Profile will fill these automatically later.</div>
            <div style="display:grid;gap:6px;margin-top:10px">
                @foreach ($this->dayLabels() as $day => $label)
                    <div style="display:flex;gap:8px;align-items:center" wire:key="hrs-{{ $day }}">
                        <span style="width:38px;font-weight:600;font-size:.85rem">{{ $label }}</span>
                        <input class="lp-input" style="max-width:120px" type="time" wire:model.blur="hours.{{ $day }}.open" @disabled($this->hours[$day]['closed'] ?? false)>
                        <span class="hint">to</span>
                        <input class="lp-input" style="max-width:120px" type="time" wire:model.blur="hours.{{ $day }}.close" @disabled($this->hours[$day]['closed'] ?? false)>
                        <label style="display:flex;gap:5px;align-items:center;font-size:.85rem;color:var(--ungrouped);cursor:pointer">
                            <input type="checkbox" wire:model.live="hours.{{ $day }}.closed"> Closed
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="lp-card">
            <h3>Reviews from your customers <span class="hint" style="font-weight:400">— optional</span></h3>
            <div class="hint">Paste real reviews from your Google / Yelp profile — they power the "In their words" sections. Only reviews you actually received; connecting your Google Business Profile will import these automatically later.</div>
            @foreach ($this->testimonials as $i => $t)
                <div style="display:flex;gap:8px;align-items:baseline;margin-top:8px" wire:key="tst-{{ $i }}">
                    <span style="flex:1">“{{ $t['quote'] }}”@if (trim($t['author']) !== '') <span class="hint">— {{ $t['author'] }}</span>@endif</span>
                    <span class="x" style="cursor:pointer" wire:click="removeTestimonial({{ $i }})">×</span>
                </div>
            @endforeach
            <div class="lp-field" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <input class="lp-input" style="flex:3;min-width:220px" wire:model="newTestimonialQuote" wire:keydown.enter.prevent="addTestimonial" placeholder="The review, word for word">
                <input class="lp-input" style="flex:1;min-width:120px" wire:model="newTestimonialAuthor" wire:keydown.enter.prevent="addTestimonial" placeholder="Name (optional)">
                <button class="lp-mini primary" wire:click="addTestimonial">Add</button>
            </div>
        </div>

        @php $added = collect($this->suggestions)->where('on', true)->count(); @endphp
        <div class="lp-foot">
            <button class="lp-btn" wire:click="proceed">Continue to territory</button>
            <span class="lp-gate ok">{{ count($this->services) }} {{ \Illuminate\Support\Str::plural('service', count($this->services)) }}@if ($added) · {{ $added }} suggestion{{ $added === 1 ? '' : 's' }} added @endif</span>
        </div>
    @endunless
</x-guided.shell>
