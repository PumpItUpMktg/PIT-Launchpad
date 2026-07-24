{{-- One service's shared controls (name + provenance chip + enrich + remove) — used at both the
     top-level and sub-service levels of the grouping tree. Expects $service. --}}
@php
    $prov = $this->provenanceFor($service);
    $seeded = collect($prov)->contains(fn ($state) => $state === \App\Enums\ProvenanceState::Seeded);
    $confirmed = $prov !== [] && ! $seeded;
    $enriched = collect($service->symptoms ?? [])->isNotEmpty() || collect($service->scope_items ?? [])->isNotEmpty() || trim((string) $service->short_description) !== '';
    $hasBlanks = collect(\App\Gathering\ServiceEnricher::FIELDS)->contains(function (string $f) use ($service) {
        $v = $service->{$f};

        return $v === null || $v === [] || (is_string($v) && trim($v) === '');
    });
@endphp
<strong>{{ $service->name }}</strong>
@if ($seeded)<span class="g-seed">seeded — review</span>@elseif ($confirmed)<span class="g-seed confirmed">confirmed</span>@endif
<span class="g-muted">{{ $enriched ? ($service->short_description ?: 'enriched') : 'not enriched yet' }}</span>
<span class="g-row" style="margin-left:auto">
    @if ($hasBlanks)
        <button class="g-btn" wire:click="aiEnrich('{{ $service->id }}')" wire:loading.attr="disabled" wire:target="aiEnrich"
            title="Draft the empty fields with generic trade knowledge (no prices or guarantees) — you review and edit before it counts.">
            <span wire:loading.remove wire:target="aiEnrich('{{ $service->id }}')">✨ AI fill</span>
            <span wire:loading wire:target="aiEnrich('{{ $service->id }}')">Drafting…</span>
        </button>
    @endif
    {{ ($this->enrich)(['service' => $service->id]) }}
    <button class="g-btn danger" wire:click="removeService('{{ $service->id }}')" wire:confirm="Remove '{{ $service->name }}'?">Remove</button>
</span>
