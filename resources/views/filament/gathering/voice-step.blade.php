<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'The voice profile drafts write with — persona, phrasing rules, audience. The interview seeds a draft from the owner\'s voice cues; edit, save to confirm, then activate.'])

        @php $draft = $this->draft; $prov = $draft !== null ? $this->provenanceFor($draft) : []; @endphp

        <div class="g-card">
            <div class="g-row" style="justify-content:space-between">
                <h3>Voice draft {{ $draft !== null ? 'v'.$draft->version : '' }}
                    @isset($prov['profile'])<span class="g-seed {{ $prov['profile']->value }}">{{ $prov['profile']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset
                </h3>
                <div class="g-row">
                    <button class="g-btn primary" wire:click="save">Save draft</button>
                    <button class="g-btn" wire:click="activate">Activate</button>
                </div>
            </div>
            <div class="g-field"><label>Persona — who the site sounds like</label><textarea class="g-textarea" rows="2" wire:model="persona"></textarea></div>
            <div class="g-field"><label>Language rules — one per line (phrasing they use, claims they make, words they'd never use)</label><textarea class="g-textarea" rows="4" wire:model="languageRules"></textarea></div>
            <div class="g-field"><label>Audience — one per line</label><textarea class="g-textarea" rows="2" wire:model="audience"></textarea></div>
            <div class="g-grid2">
                <div class="g-field"><label>Reading level</label><input class="g-input" type="text" wire:model="readingLevel" placeholder="e.g. 8th grade"></div>
                <div class="g-field"><label>CTA voice</label><input class="g-input" type="text" wire:model="ctaVoice" placeholder="e.g. direct, no pressure"></div>
            </div>
        </div>

        <div class="g-card">
            <h3>All versions</h3>
            <div class="g-list">
                @forelse ($this->profiles as $profile)
                    <div class="g-item" wire:key="vp-{{ $profile->id }}">
                        <strong>v{{ $profile->version }}</strong>
                        <span class="g-seed {{ $profile->status->value === 'active' ? 'confirmed' : '' }}">{{ $profile->status->value }}</span>
                        <span class="g-muted">{{ $profile->framing_model }}</span>
                    </div>
                @empty
                    <div class="g-empty">No voice profiles yet — save a draft above or let the interview seed one.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
