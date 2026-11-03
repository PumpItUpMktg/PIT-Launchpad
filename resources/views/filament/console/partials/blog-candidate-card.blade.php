{{-- One candidate card. Expects $c (a candidate row from BlogBoard::candidates). Extracted so the
     grouped board (silo → local-first, capped) can render each row without duplicating the markup. --}}
<div class="bc-card" wire:key="cand-{{ $c['id'] }}">
    {{-- Top line: silo · source · classification pill · date --}}
    <div class="bc-top">
        @if (! empty($c['silo'])) <span class="bc-chip silo">{{ $c['silo'] }}</span> @endif
        @if (! empty($c['source'])) <span class="bc-chip source">{{ $c['source'] }}</span> @endif
        @if (! empty($c['classification'])) <span class="bc-chip pill-{{ $c['classification_kind'] }}">{{ $c['classification'] }}</span> @endif
        @if (! empty($c['date'])) <span class="bc-chip date">🗓 {{ $c['date'] }}</span> @endif
    </div>

    {{-- Title --}}
    <p class="bc-title">{{ $c['title'] ?: 'Untitled candidate' }}</p>

    {{-- Longtail keyword (+ provenance flags) --}}
    @if (! empty($c['keyword']) || ($c['directed'] ?? false) || ($c['revived'] ?? false) || ! empty($c['question']))
        <div class="bc-kw">
            @if (! empty($c['keyword'])) <span class="k">Target:</span> {{ $c['keyword'] }} @endif
            @if ($c['directed'] ?? false) <span class="bc-tag directed">Directed</span> @endif
            @if ($c['revived'] ?? false) <span class="bc-tag revived">Revival · {{ number_format($c['revived_impressions'] ?? 0) }} impr</span> @endif
            @if (! empty($c['question']))
                @php $q = $c['question']; @endphp
                <span class="bc-tag directed">Asked {{ number_format($q['impressions']) }}×@if ($q['position'] !== null) · pos {{ number_format($q['position'], 1) }}@endif</span>
                @if ($q['phrasings'] !== []) <span class="k">Also:</span> {{ implode(' · ', array_slice($q['phrasings'], 0, 3)) }} @endif
            @endif
        </div>
    @endif

    {{-- Excerpt --}}
    @if (! empty($c['excerpt'])) <div class="bc-excerpt">{{ $c['excerpt'] }}</div> @endif

    {{-- Footer: Generate / Dismiss + prominent score --}}
    <div class="bc-foot">
        <div class="bc-actions">
            @if ($this->can(\App\Security\Capability::GenerateContent))
                <button class="bc-btn primary" wire:click="promote('{{ $c['id'] }}')" wire:loading.attr="disabled" wire:target="promote('{{ $c['id'] }}')">
                    <span wire:loading.remove wire:target="promote('{{ $c['id'] }}')">Generate</span>
                    <span wire:loading wire:target="promote('{{ $c['id'] }}')">Working…</span>
                </button>
            @endif
            @if ($this->can(\App\Security\Capability::EditContent))
                <button class="bc-btn" wire:click="dismiss('{{ $c['id'] }}')" wire:confirm="Dismiss this candidate?">Dismiss</button>
            @endif
        </div>
        @if (($c['score'] ?? null) !== null)
            @php $s = (float) $c['score']; @endphp
            <div class="bc-score {{ $s >= 0.7 ? '' : ($s >= 0.4 ? 'mid' : 'low') }}">
                <span class="n">{{ number_format($s, 2) }}</span>
                <span class="l">Score</span>
            </div>
        @endif
    </div>
</div>
