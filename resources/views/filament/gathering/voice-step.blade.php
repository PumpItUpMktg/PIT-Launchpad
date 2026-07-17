<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'How every page and post will sound. The interview seeds a draft; jot rough notes and let AI shape them (or write it yourself), edit until it reads like the owner, Save, then Activate.'])

        @php $draft = $this->draft; $prov = $draft !== null ? $this->provenanceFor($draft) : []; @endphp

        <div class="g-card">
            <div class="g-row" style="justify-content:space-between">
                <h3>Voice draft {{ $draft !== null ? 'v'.$draft->version : '' }}
                    @isset($prov['profile'])<span class="g-seed {{ $prov['profile']->value }}">{{ $prov['profile']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset
                </h3>
                <div class="g-row">
                    <button class="g-btn" wire:click="aiEnhance" wire:loading.attr="disabled" wire:target="aiEnhance"
                        title="Rough notes are fine — AI keeps your meaning and phrases, tightens and fills the gaps. Nothing is stored until you Save.">
                        <span wire:loading.remove wire:target="aiEnhance">✨ AI enhance</span>
                        <span wire:loading wire:target="aiEnhance">Shaping…</span>
                    </button>
                    <button class="g-btn primary" wire:click="save">Save draft</button>
                    <button class="g-btn" wire:click="activate">Activate</button>
                </div>
            </div>
            <div class="g-field">
                <label>How the site should sound</label>
                <textarea class="g-textarea" rows="2" wire:model="persona" placeholder="e.g. A straight-talking second-generation owner — explains things simply, no sales fluff"></textarea>
            </div>
            <div class="g-field">
                <label>Say it like this / never say this <span class="g-muted">(one per line)</span></label>
                <textarea class="g-textarea" rows="4" wire:model="languageRules" placeholder="We say &quot;dry basement, guaranteed process&quot;&#10;Never say &quot;cheap&quot; or &quot;best in town&quot;"></textarea>
            </div>
            <div class="g-field">
                <label>Who you're talking to <span class="g-muted">(one per line)</span></label>
                <textarea class="g-textarea" rows="2" wire:model="audience" placeholder="Homeowners with wet basements&#10;Property managers"></textarea>
            </div>
            <div class="g-grid2">
                <div class="g-field">
                    <label>How simple should the writing be?</label>
                    <input class="g-input" type="text" wire:model="readingLevel" placeholder="e.g. everyday plain English">
                </div>
                <div class="g-field">
                    <label>How to ask for the call</label>
                    <input class="g-input" type="text" wire:model="ctaVoice" placeholder="e.g. direct but no pressure">
                </div>
            </div>
            <p class="g-hint">Rough notes are enough — ✨ AI enhance shapes them into a clean profile (your words kept, no invented claims). Save stores the draft; Activate is what makes every page write in this voice.</p>
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
