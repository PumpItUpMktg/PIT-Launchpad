<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'Confirm and gap-fill each location — served towns (one per line, "Town, ST"; a town belongs to exactly one location), market notes, storefront. Interview-seeded values are chipped; saving confirms them.'])

        @forelse ($this->locations as $location)
            @php
                $prov = $this->provenanceFor($location);
                $suggestions = (array) ($location->coverage_suggestions ?? []);
                $sugTowns = (array) ($suggestions['towns'] ?? []);
                $sugPhrases = (array) ($suggestions['phrases'] ?? []);
            @endphp
            <div class="g-card" wire:key="loc-{{ $location->id }}">
                <div class="g-row" style="justify-content:space-between">
                    <div>
                        <h3>{{ $location->name }}</h3>
                        <span class="g-muted">{{ $location->address ?: 'No address on file' }}{{ $location->phone ? ' · '.$location->phone : '' }}</span>
                    </div>
                    <label class="g-row" style="gap:6px; font-size:12.5px">
                        <input type="checkbox" wire:model="storefront.{{ $location->id }}"> Storefront (walk-in)
                    </label>
                </div>

                @if ($sugTowns !== [] || $sugPhrases !== [])
                    <div class="g-empty" style="border-color:rgba(217,119,6,.5); color:#b45309">
                        <strong>From the interview — needs your judgment:</strong>
                        @if ($sugPhrases !== [])
                            <div>Coverage described as: {{ implode(' · ', array_map('strval', $sugPhrases)) }}</div>
                        @endif
                        @if ($sugTowns !== [])
                            <div>Suggested towns (conflict or unconfirmed): {{ implode(', ', array_map('strval', $sugTowns)) }}</div>
                        @endif
                        <button class="g-btn" style="margin-top:6px" wire:click="dismissSuggestions('{{ $location->id }}')">Handled — dismiss</button>
                    </div>
                @endif

                <div class="g-field">
                    <label>Served towns — one per line
                        @isset($prov['served_towns'])<span class="g-seed {{ $prov['served_towns']->value }}">{{ $prov['served_towns']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset
                    </label>
                    <textarea class="g-textarea" rows="5" wire:model="towns.{{ $location->id }}" placeholder="Norristown, PA&#10;Audubon, PA"></textarea>
                </div>

                <div class="g-field">
                    <label>Market notes — local knowledge only the owner has
                        @isset($prov['market_notes'])<span class="g-seed {{ $prov['market_notes']->value }}">{{ $prov['market_notes']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset
                    </label>
                    <textarea class="g-textarea" rows="3" wire:model="notes.{{ $location->id }}"></textarea>
                </div>

                <button class="g-btn primary" wire:click="saveLocation('{{ $location->id }}')">Save & confirm</button>
            </div>
        @empty
            <div class="g-empty">No locations yet — create skeletons with the GBP import on the Business step (or add them under Settings → Locations).</div>
        @endforelse
    </div>
</x-filament-panels::page>
