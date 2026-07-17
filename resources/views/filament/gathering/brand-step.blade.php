<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'The look and the words: pick a style (voice-recommended, override any time), apply it to WordPress global styles, and give the story / mission / values / team the About and Why-Choose-Us pages are written from. Voice itself lives on the Interview and Voice steps.'])

        @php
            $resolved = $this->resolvedStyle;
            $chosen = $this->chosenStyle;
            $usesLogo = $this->usesLogoColors;
            $logo = $this->logoColors;
        @endphp

        {{-- ── Logo ── --}}
        <div class="g-card">
            <h3>Logo <span class="g-muted" style="font-weight:400">— optional</span></h3>
            <p class="g-hint">Uploaded to your site header. If a clear brand color is found, it becomes the "Your brand colors" style option below.</p>
            @if ($logoInfo)
                <div class="g-row" style="align-items:center">
                    <img src="{{ $logoInfo['url'] }}" alt="Your logo" style="height:52px;width:auto;max-width:180px;object-fit:contain;background:#fff;border:1px solid rgba(148,163,184,.4);border-radius:8px;padding:6px">
                    @if ($logoInfo['primary'])
                        <span title="{{ $logoInfo['primary'] }}" style="width:22px;height:22px;border-radius:50%;background:{{ $logoInfo['primary'] }};border:1px solid rgba(148,163,184,.4)"></span>
                        @if ($logoInfo['accent'])
                            <span title="{{ $logoInfo['accent'] }}" style="width:22px;height:22px;border-radius:50%;background:{{ $logoInfo['accent'] }};border:1px solid rgba(148,163,184,.4)"></span>
                        @endif
                    @else
                        <span class="g-muted">No clear brand color found — the logo is still in your header.</span>
                    @endif
                    <button type="button" class="g-btn danger" style="margin-left:auto" wire:click="removeLogo">Remove</button>
                </div>
                <div class="g-field"><label>Replace</label><input type="file" wire:model="logoUpload" accept="image/png,image/jpeg,image/svg+xml"></div>
            @else
                <div class="g-field">
                    <input type="file" wire:model="logoUpload" accept="image/png,image/jpeg,image/svg+xml">
                    <div wire:loading wire:target="logoUpload" class="g-muted" style="margin-top:4px">Processing…</div>
                </div>
            @endif
        </div>

        {{-- ── Look ── --}}
        <div class="g-card">
            <h3>Look
                @if ($this->pushed)<span class="g-seed confirmed" style="margin-left:6px">applied</span>@endif
            </h3>
            <p class="g-hint">One of three theme.json style variations — recommended from the brand voice; picking one overrides. Applying activates it as the site's global styles.</p>
            <div class="g-row">
                <button type="button" class="g-btn {{ $chosen === null && ! $usesLogo ? 'primary' : '' }}" wire:click="chooseStyle('auto')">
                    Auto{{ $chosen === null && ! $usesLogo && $resolved !== null ? ' — '.$resolved->label() : '' }}
                </button>
                @foreach (\App\Styling\StyleVariation::cases() as $v)
                    @php $t = $v->tokens(); @endphp
                    <button type="button" class="g-btn {{ $chosen === $v && ! $usesLogo ? 'primary' : '' }}" wire:click="chooseStyle('{{ $v->value }}')" title="{{ $v->blurb() }}">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:{{ $t['primary'] }}"></span>
                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:{{ $t['accent'] }}"></span>
                        {{ $v->label() }}
                    </button>
                @endforeach
                @if ($logo !== null)
                    <button type="button" class="g-btn {{ $usesLogo ? 'primary' : '' }}" wire:click="chooseStyle('brand_colors')" title="Derived from your logo">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:{{ $logo['primary'] }}"></span>
                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:{{ $logo['accent'] }}"></span>
                        Your brand colors
                    </button>
                @endif
            </div>
            <button type="button" class="g-btn primary" wire:click="pushBrand" wire:loading.attr="disabled" wire:target="pushBrand">
                <span wire:loading.remove wire:target="pushBrand">Apply {{ $usesLogo ? 'your brand colors' : ($resolved?->label() ?? 'your look') }} to the site</span>
                <span wire:loading wire:target="pushBrand">Applying…</span>
            </button>
        </div>

        {{-- ── Narrative ── --}}
        <div class="g-card">
            <h3>Story & mission</h3>
            <p class="g-hint">The words About and Why-Choose-Us are grounded on — optional, but a page missing its intake holds rather than fabricates.</p>
            <div class="g-field"><label>Your story</label><textarea class="g-textarea" rows="3" wire:model="story" placeholder="How the business started, who runs it, what it stands for…"></textarea></div>
            <div class="g-field">
                <label>Mission</label>
                <textarea class="g-textarea" rows="2" wire:model="mission"></textarea>
                <label class="g-muted" style="display:flex;align-items:center;gap:6px;margin-top:4px">
                    <input type="checkbox" wire:model="missionEnhance"> Polish my wording (grammar and tightening only — never new claims; your original is kept)
                </label>
            </div>
            <div class="g-grid2">
                <div class="g-field"><label>Values <span class="g-muted">(one per line)</span></label><textarea class="g-textarea" rows="3" wire:model="valuesText"></textarea></div>
                <div class="g-field"><label>Differentiators <span class="g-muted">(one per line)</span></label><textarea class="g-textarea" rows="3" wire:model="differentiatorsText"></textarea></div>
            </div>
            <button type="button" class="g-btn primary" wire:click="saveNarrative">Save brand details</button>
        </div>

        {{-- ── Team ── --}}
        <div class="g-card">
            <h3>Team</h3>
            <p class="g-hint">Real faces are the strongest trust content on the site — a member without a photo renders an initials chip, never a fabricated headshot. Add and remove persist immediately.</p>
            @if ($team !== [])
                <div class="g-list">
                    @foreach ($team as $i => $member)
                        <div class="g-item" wire:key="team-{{ $i }}">
                            @if ($member['photo_url'] !== '')
                                <img src="{{ $member['photo_url'] }}" alt="{{ $member['name'] }}" style="width:34px;height:34px;border-radius:50%;object-fit:cover">
                            @else
                                <span style="width:34px;height:34px;border-radius:50%;background:rgba(148,163,184,.25);display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">{{ \Illuminate\Support\Str::of($member['name'])->substr(0, 1) }}</span>
                            @endif
                            <strong>{{ $member['name'] }}</strong>
                            <span class="g-muted">{{ $member['role'] }}</span>
                            <button type="button" class="g-btn danger" style="margin-left:auto" wire:click="removeTeamMember({{ $i }})">Remove</button>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="g-grid2">
                <div class="g-field"><label>Name</label><input class="g-input" type="text" wire:model="newTeamName"></div>
                <div class="g-field"><label>Role</label><input class="g-input" type="text" wire:model="newTeamRole"></div>
            </div>
            <div class="g-field"><label>Short bio</label><textarea class="g-textarea" rows="2" wire:model="newTeamBio"></textarea></div>
            <div class="g-field"><label>Photo <span class="g-muted">(optional — real photos highly recommended)</span></label><input type="file" wire:model="teamPhoto" accept="image/png,image/jpeg,image/webp"></div>
            <button type="button" class="g-btn" wire:click="addTeamMember" wire:loading.attr="disabled" wire:target="addTeamMember,teamPhoto">Add member</button>
        </div>
        @include('filament.gathering._next')
    </div>
</x-filament-panels::page>
