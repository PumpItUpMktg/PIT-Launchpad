@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Brand->eyebrow() }}</div>
    <h1 class="lp-h1">Your brand</h1>
    <p class="lp-lede">Set your brand voice, pick a look, and give us the words your pages are written from. Your look is a real WordPress style — no page builder, nothing locked in.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @else
        {{-- Brand voice FIRST: it drives the recommended look below (voice → style). Optional — skip it
             and pages use a plain default voice + the default look. --}}
        <div class="lp-card">
            <h3>Your voice @if ($this->voiceSet)<span class="lp-gate ok" style="margin-left:8px">voice set</span>@endif</h3>
            <div class="hint">How your pages should sound — and what we recommend a look from. Optional; without it we use a plain, friendly default.</div>

            <div class="lp-field">
                <label>Tone</label>
                <select class="lp-input" wire:model="voiceTone">
                    <option value="friendly_warm">Friendly &amp; warm</option>
                    <option value="professional_warm">Professional &amp; warm</option>
                    <option value="direct_expert">Direct &amp; expert</option>
                </select>
            </div>
            <div class="lp-field">
                <label>Who you're talking to</label>
                <input class="lp-input" wire:model="voiceAudience" placeholder="e.g. homeowners, property managers">
            </div>
            <div class="lp-field">
                <label>What makes you credible</label>
                <input class="lp-input" wire:model="voiceCredibility" placeholder="e.g. licensed, insured, 20 years in the trade">
            </div>

            <button class="lp-mini" wire:click="saveVoice">{{ $this->voiceSet ? 'Update brand voice' : 'Set brand voice' }}</button>
        </div>

        {{-- Your look: one of three theme.json style variations. Recommended from the voice above;
             override to any. Applying activates it on the site's WordPress global styles (theme.json,
             not a page builder). --}}
        @php $resolved = $this->resolvedStyle; $chosen = $this->chosenStyle; $logo = $this->logoColors; $usesLogo = $this->usesLogoColors; @endphp
        <div class="lp-card">
            <h3>Your look</h3>
            <div class="hint">We recommend a style from your brand voice — pick any. Same site &amp; content, restyled instantly. Applying sets it on your WordPress.</div>

            <div class="lp-chips" style="margin:12px 0">
                @foreach (\App\Styling\StyleVariation::cases() as $v)
                    @php $t = $v->tokens(); $active = ! $usesLogo && $resolved === $v; @endphp
                    <button type="button" class="lp-chip @if ($active) primary @endif" wire:click="chooseStyle('{{ $v->value }}')" style="cursor:pointer;gap:7px">
                        <span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:{{ $t['primary'] }};border:1px solid var(--line)"></span>
                        <span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:{{ $t['accent'] }};border:1px solid var(--line)"></span>
                        {{ $v->label() }}
                        @if ($active && $chosen === null)<span class="lp-gate ok" style="margin-left:6px">recommended</span>@endif
                    </button>
                @endforeach

                {{-- Data-gated: only when a usable logo palette was extracted. --}}
                @if ($logo)
                    <button type="button" class="lp-chip @if ($usesLogo) primary @endif" wire:click="chooseStyle('brand_colors')" style="cursor:pointer;gap:7px">
                        <span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:{{ $logo['primary'] }};border:1px solid var(--line)"></span>
                        <span style="display:inline-block;width:13px;height:13px;border-radius:3px;background:{{ $logo['accent'] }};border:1px solid var(--line)"></span>
                        Your brand colors
                        <span class="lp-gate" style="margin-left:6px;opacity:.65">pulled from your logo</span>
                    </button>
                @endif
            </div>

            @php $res = $this->styleResolution; @endphp
            @if ($res['label'] !== '')
                <div style="margin:8px 0;padding:8px 11px;border-radius:8px;font-size:12px;
                            background:{{ $res['shadows_curated'] ? '#fffbeb' : '#f8fafc' }};
                            border:1px solid {{ $res['shadows_curated'] ? '#fcd34d' : 'rgba(148,163,184,.4)' }};
                            color:{{ $res['shadows_curated'] ? '#92400e' : '#334155' }}">
                    Applying will push <strong>{{ $res['label'] }}</strong>.
                    @if ($res['shadows_curated'])
                        Your curated pick (<strong>{{ $res['curated_label'] }}</strong>) is ignored while "Your brand colors" is selected — pick {{ $res['curated_label'] }} above to switch off the logo colors.
                    @elseif ($res['is_logo'])
                        These come from your logo. Pick any curated style above to use it instead.
                    @endif
                </div>
            @endif

            <div style="display:flex;gap:8px;align-items:center">
                <button class="lp-mini primary" wire:click="pushBrand">Apply {{ $usesLogo ? 'your brand colors' : ($resolved?->label() ?? 'your look') }} to your site</button>
                @if ($chosen !== null || $usesLogo)
                    <button class="lp-mini" wire:click="chooseStyle('auto')">Use the recommended</button>
                @endif
            </div>
        </div>

        {{-- Brand narrative: the words the About / Why-Choose-Us pages are written from. Optional — a
             blank field is simply left out (never invented); a missing required one holds that page
             "needs intake" rather than drafting generic copy. --}}
        <div class="lp-card">
            <h3>Your story</h3>
            <div class="hint">The words your About and Why Choose Us pages are written from — in your voice, never invented. Leave a field blank and that part is simply left out.</div>

            <div class="lp-field">
                <label>Brand story <span style="font-weight:400;color:var(--ungrouped)">— your About page needs this</span></label>
                <textarea class="lp-input" rows="5" wire:model="story" placeholder="How {{ $brand }} started, who you serve, and what you stand for."></textarea>
            </div>
            <div class="lp-field">
                <label>Mission <span style="font-weight:400;color:var(--ungrouped)">— optional</span></label>
                <textarea class="lp-input" rows="2" wire:model="mission" placeholder="What you commit to for every customer."></textarea>
                <label style="display:flex;align-items:flex-start;gap:.5rem;margin-top:.4rem;font-weight:400;font-size:.85rem;color:var(--ungrouped);cursor:pointer">
                    <input type="checkbox" wire:model="missionEnhance" style="margin-top:.15rem">
                    <span>Polish my wording with AI — tightens grammar and clarity only, never adds claims. Leave unchecked to publish your mission exactly as written.</span>
                </label>
            </div>
            <div class="lp-field">
                <label>Values <span style="font-weight:400;color:var(--ungrouped)">— optional, one per line</span></label>
                <textarea class="lp-input" rows="3" wire:model="valuesText" placeholder="On time, every time&#10;Quote before we start&#10;Leave it cleaner than we found it"></textarea>
            </div>
            <div class="lp-field">
                <label>Differentiators <span style="font-weight:400;color:var(--ungrouped)">— your Why Choose Us page needs this, one per line</span></label>
                <textarea class="lp-input" rows="3" wire:model="differentiatorsText" placeholder="Licensed &amp; insured&#10;Written warranty on every job&#10;Same-day service"></textarea>
            </div>

            <button class="lp-mini" wire:click="saveNarrative">Save brand details</button>
        </div>

        <div class="lp-card">
            <h3>Your team <span class="hint" style="font-weight:400">— optional</span></h3>
            <div class="hint">The people behind the work — they render on your About page. REAL photos are highly recommended (a real face builds more trust than anything generated); a member without one shows an initials chip, never a stock face.</div>

            @foreach ($this->team as $i => $m)
                <div style="display:flex;gap:10px;align-items:center;margin-top:10px" wire:key="team-{{ $i }}">
                    @if (trim($m['photo_url']) !== '')
                        <img src="{{ $m['photo_url'] }}" alt="{{ $m['name'] }}" style="width:34px;height:34px;border-radius:50%;object-fit:cover">
                    @else
                        <span style="width:34px;height:34px;border-radius:50%;background:var(--surface,#eef2f7);display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">{{ mb_strtoupper(mb_substr($m['name'], 0, 1)) }}</span>
                    @endif
                    <span style="flex:1"><strong>{{ $m['name'] }}</strong>@if (trim($m['role']) !== '') <span class="hint">— {{ $m['role'] }}</span>@endif @if (trim($m['photo_url']) === '')<span class="hint" style="color:#d97706"> · no photo yet</span>@endif</span>
                    <span class="x" style="cursor:pointer" wire:click="removeTeamMember({{ $i }})">×</span>
                </div>
            @endforeach

            <div class="lp-field" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                <input class="lp-input" style="flex:1;min-width:140px" wire:model="newTeamName" placeholder="Name">
                <input class="lp-input" style="flex:1;min-width:140px" wire:model="newTeamRole" placeholder="Role (e.g. Master Plumber)">
            </div>
            <div class="lp-field" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input class="lp-input" style="flex:2;min-width:200px" wire:model="newTeamBio" placeholder="One line about them (optional)">
                <input type="file" accept="image/png,image/jpeg,image/webp" wire:model="teamPhoto" style="flex:1;min-width:170px;font-size:.85rem">
                <button class="lp-mini primary" wire:click="addTeamMember" wire:loading.attr="disabled" wire:target="addTeamMember,teamPhoto">Add member</button>
            </div>
            <div class="hint" wire:loading wire:target="teamPhoto">Uploading photo…</div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::ConnectWordpress->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="proceed" @disabled(! $this->pushed)>Continue to territory</button>
            @if ($this->pushed)
                <span class="lp-gate ok">Look applied to your site</span>
            @else
                <span class="lp-gate">Apply your look to continue</span>
            @endif
        </div>
    @endunless
</x-guided.shell>
