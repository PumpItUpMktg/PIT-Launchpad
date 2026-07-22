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
            <p class="g-hint">Ten theme variations, each a full six-color palette (base · surface · text · primary · highlight · button). Your logo palette comes first, then the voice-recommended pick, then the rest — choosing one overrides; Applying activates it as the site's global styles.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:10px;margin-top:6px">
                @foreach ($this->styleOptions as $opt)
                    <button type="button"
                        wire:click="chooseStyle('{{ $opt['key'] }}')"
                        title="{{ $opt['blurb'] }}"
                        style="text-align:left;padding:0;border-radius:11px;overflow:hidden;cursor:pointer;background:transparent;
                               border:2px solid {{ $opt['chosen'] ? '#2563eb' : 'rgba(148,163,184,.4)' }};
                               box-shadow:{{ $opt['chosen'] ? '0 0 0 3px rgba(37,99,235,.18)' : 'none' }}">
                        {{-- palette preview strip --}}
                        <span style="display:flex;height:44px">
                            @foreach ($opt['swatches'] as $hex)
                                <span style="flex:1;background:{{ $hex }}"></span>
                            @endforeach
                        </span>
                        <span style="display:block;padding:8px 10px 10px;background:#fff">
                            <span style="display:flex;align-items:center;gap:6px">
                                <strong style="font-size:12.5px;color:#0f172a">{{ $opt['label'] }}</strong>
                                @if ($opt['badge'])
                                    <span style="font-size:9px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:2px 6px;border-radius:999px;
                                                 background:{{ $opt['badge'] === 'From your logo' ? '#ecfdf5' : '#eef2ff' }};
                                                 color:{{ $opt['badge'] === 'From your logo' ? '#047857' : '#4338ca' }}">{{ $opt['badge'] }}</span>
                                @endif
                                @if ($opt['chosen'])<span style="margin-left:auto;font-size:10px;font-weight:700;color:#2563eb">✓ Selected</span>@endif
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
            <button type="button" class="g-btn" style="margin-top:8px" wire:click="chooseStyle('auto')">
                Follow the voice recommendation (Auto{{ $chosen === null && ! $usesLogo && $resolved !== null ? ' — '.$resolved->label() : '' }})
            </button>

            @php $res = $this->styleResolution; @endphp
            @if ($res['label'] !== '')
                <div style="margin-top:10px;padding:9px 12px;border-radius:9px;font-size:12.5px;
                            background:{{ $res['shadows_curated'] ? '#fffbeb' : '#f8fafc' }};
                            border:1px solid {{ $res['shadows_curated'] ? '#fcd34d' : 'rgba(148,163,184,.4)' }};
                            color:{{ $res['shadows_curated'] ? '#92400e' : '#334155' }}">
                    Applying will push <strong>{{ $res['label'] }}</strong>.
                    @if ($res['shadows_curated'])
                        <br>Your curated pick (<strong>{{ $res['curated_label'] }}</strong>) is being ignored while
                        "Your brand colors" is selected — pick {{ $res['curated_label'] }} above to switch off the logo colors.
                    @elseif ($res['is_logo'])
                        <br>These come from your logo. Pick any curated style above to use it instead.
                    @endif
                </div>
            @endif

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
