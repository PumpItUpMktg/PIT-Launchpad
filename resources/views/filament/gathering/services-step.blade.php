<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'The stated services list — exhaustive, including zero-volume work — with per-service enrichment (the same form the service pages build from). Interview-seeded services are chipped; saving the enrichment confirms them.'])

        <div class="g-card">
            <h3>Add a service</h3>
            <div class="g-row">
                <input class="g-input" style="max-width:360px" type="text" placeholder="e.g. French Drain Installation" wire:model="newService" wire:keydown.enter="addService">
                <button class="g-btn primary" wire:click="addService">Add</button>
                <button class="g-btn" wire:click="suggest" wire:loading.attr="disabled" wire:target="suggest"
                    title="Services a {{ $this->trade !== '' ? $this->trade : 'business in your trade' }} commonly also offers — advisory, from the trade on the Business step.">
                    <span wire:loading.remove wire:target="suggest">✨ Suggest from trade</span>
                    <span wire:loading wire:target="suggest">Thinking…</span>
                </button>
            </div>
            @if ($suggestions !== [])
                <div class="g-hint" style="margin-top:2px">Commonly also offered by a {{ $this->trade }} business — add what applies, dismiss the rest:</div>
                <div class="g-list">
                    @foreach ($suggestions as $i => $s)
                        <div class="g-item" wire:key="sug-{{ $i }}-{{ \Illuminate\Support\Str::slug($s['name']) }}">
                            <strong>{{ $s['name'] }}</strong>
                            <span class="g-muted">{{ $s['why'] }}</span>
                            <span class="g-row" style="margin-left:auto">
                                <button class="g-btn primary" wire:click="addSuggestion({{ $i }})">Add</button>
                                <button class="g-btn" wire:click="dismissSuggestion({{ $i }})">Dismiss</button>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="g-card">
            <h3>Stated services</h3>
            <div class="g-list">
                @forelse ($this->services as $service)
                    @php
                        $prov = $this->provenanceFor($service);
                        $seeded = collect($prov)->contains(fn ($state) => $state === \App\Enums\ProvenanceState::Seeded);
                        $confirmed = $prov !== [] && ! $seeded;
                        $enriched = collect($service->symptoms ?? [])->isNotEmpty() || collect($service->scope_items ?? [])->isNotEmpty() || trim((string) $service->short_description) !== '';
                    @endphp
                    <div class="g-item" wire:key="svc-{{ $service->id }}">
                        <strong>{{ $service->name }}</strong>
                        @if ($seeded)<span class="g-seed">from interview</span>@elseif ($confirmed)<span class="g-seed confirmed">confirmed</span>@endif
                        <span class="g-muted">{{ $enriched ? ($service->short_description ?: 'enriched') : 'not enriched yet' }}</span>
                        <span class="g-row" style="margin-left:auto">
                            {{ ($this->enrich)(['service' => $service->id]) }}
                            <button class="g-btn danger" wire:click="removeService('{{ $service->id }}')" wire:confirm="Remove '{{ $service->name }}'?">Remove</button>
                        </span>
                    </div>
                @empty
                    <div class="g-empty">No services yet — add them above, or let the interview seed the stated list.</div>
                @endforelse
            </div>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
