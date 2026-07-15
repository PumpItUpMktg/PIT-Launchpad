<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'Assign territory to each physical location: tick the counties it serves, then the towns worth a page (grouped by size). One town belongs to one location — overlap shows on the Operate → Physical locations board. Interview-seeded values are chipped; saving confirms.'])

        @include('filament.pages.partials.locations.styles')

        @php
            $tierMeta = [
                'major' => ['label' => 'Major', 'color' => '#0A4F4F'],
                'large' => ['label' => 'Large', 'color' => '#0E6B6B'],
                'medium' => ['label' => 'Medium', 'color' => '#4E9A98'],
                'small' => ['label' => 'Small', 'color' => '#A6CFCD'],
                'ungrouped' => ['label' => 'Ungrouped', 'color' => '#C3CCD6'],
            ];
            $locations = $this->locations;
            $colors = $this->colors;
            $locating = $locations->contains(fn ($l) => $l->lat === null && ! $l->geocode_failed);
            $vm = $this->panels;
            $totals = $vm['totals'];
            $tierTotals = $totals['tiers'] ?? [];
            $activeLoc = $locations->firstWhere('id', $activeTab) ?? $locations->first();
            $activePanel = $activeLoc ? ($vm['panels'][$activeLoc->id] ?? null) : null;
            $overlap = $totals['overlap'] ?? 0;
        @endphp

        @if ($this->geocoderWarning)
            <div class="lp-wrap"><div class="lp-warn">⚠ {{ $this->geocoderWarning }}</div></div>
        @endif

        <div class="lp-wrap">
            @if (! $locations->isEmpty())
                @include('filament.pages.partials.locations.hero')
            @endif

            {{-- One location's territory at a time — the tabs are the anti-clutter design. --}}
            @include('filament.pages.partials.locations.tabs')

            @if ($adding)
                @include('filament.pages.partials.locations.add-panel')
            @elseif ($activeLoc)
                @php
                    $prov = $this->provenanceFor($activeLoc);
                    $suggestions = (array) ($activeLoc->coverage_suggestions ?? []);
                    $sugTowns = (array) ($suggestions['towns'] ?? []);
                    $sugPhrases = (array) ($suggestions['phrases'] ?? []);
                    $assignedTowns = collect($activeLoc->served_towns ?? [])
                        ->map(fn ($t) => trim((string) ($t['name'] ?? '')).(trim((string) ($t['state'] ?? '')) !== '' ? ', '.trim((string) $t['state']) : ''))
                        ->filter()->values();
                @endphp

                {{-- The interview's coverage prompts sit ABOVE the picker — tick, then dismiss. --}}
                @if ($sugTowns !== [] || $sugPhrases !== [])
                    <div class="lp-card" style="border-color:rgba(217,119,6,.5)">
                        <div class="lp-seclbl" style="color:#b45309">From the interview — needs your judgment</div>
                        @if ($sugPhrases !== [])
                            <div style="font-size:13px">Coverage described as: <em>{{ implode(' · ', array_map('strval', $sugPhrases)) }}</em></div>
                        @endif
                        @if ($sugTowns !== [])
                            <div style="font-size:13px">Suggested towns: {{ implode(', ', array_map('strval', $sugTowns)) }} — tick them in the counties below.</div>
                        @endif
                        <div class="lp-row" style="margin-top:8px">
                            <button type="button" class="lp-btn ghost" wire:click="dismissSuggestions('{{ $activeLoc->id }}')">Handled — dismiss</button>
                        </div>
                    </div>
                @endif

                {{-- The territory picker: counties → tiered towns → add-a-town (shared workspace). --}}
                @include('filament.pages.partials.locations.panel')

                {{-- Gathering details for THIS location: notes + storefront; save confirms seeded. --}}
                <div class="lp-card lp-panel" wire:key="gdetails-{{ $activeLoc->id }}">
                    @if ($assignedTowns->isNotEmpty())
                        <div>
                            <div class="lp-seclbl">Town pages assigned to {{ $activeLoc->name }}
                                @isset($prov['served_towns'])
                                    <span class="g-seed {{ $prov['served_towns']->value }}">{{ $prov['served_towns']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>
                                @endisset
                            </div>
                            <div class="lp-chips">
                                @foreach ($assignedTowns as $town)
                                    <span class="lp-chip on" style="cursor:default">{{ $town }}</span>
                                @endforeach
                            </div>
                            <div class="lp-muted" style="margin-top:6px">Groups this location's town pages on the boards — fine-grained edits live in Settings → Locations.</div>
                        </div>
                    @endif

                    <div>
                        <div class="lp-seclbl">Market notes — local knowledge only the owner has
                            @isset($prov['market_notes'])
                                <span class="g-seed {{ $prov['market_notes']->value }}">{{ $prov['market_notes']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>
                            @endisset
                        </div>
                        <textarea class="lp-input" rows="3" wire:model="notes.{{ $activeLoc->id }}"></textarea>
                    </div>

                    <div class="lp-row" style="justify-content:space-between">
                        <label class="lp-muted" style="display:flex;gap:7px;align-items:center;font-size:13px">
                            <input type="checkbox" wire:model="storefront.{{ $activeLoc->id }}"> Storefront (walk-in) location
                        </label>
                        <button type="button" class="lp-btn" wire:click="saveDetails('{{ $activeLoc->id }}')">Save &amp; confirm</button>
                    </div>
                </div>
            @elseif ($locations->isEmpty())
                <div class="lp-card lp-empty">No locations yet — import them with the GBP bulk import on the Business step, or add one here.</div>
            @endif

            {{-- ONE view of everywhere this business works — all pins, every county, added towns. --}}
            @if (! $locations->isEmpty())
                @include('filament.pages.partials.locations.map')
            @endif
        </div>
    </div>
</x-filament-panels::page>
