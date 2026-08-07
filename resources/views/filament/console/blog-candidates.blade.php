<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $candidates = $this->candidates; @endphp

    <style>
        .cc-list { display:flex; flex-direction:column; gap:10px; }
        .cc-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; padding:13px 15px; display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; }
        .cc-main { flex:1 1 320px; min-width:0; display:flex; flex-direction:column; gap:5px; }
        .cc-title { font-size:14.5px; font-weight:650; margin:0; }
        .cc-meta { display:flex; gap:8px; flex-wrap:wrap; font-size:11.5px; color:#94a3b8; }
        .cc-tag { display:inline-flex; align-items:center; gap:5px; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.14); }
        .cc-tag.directed { background:rgba(79,70,229,.13); color:#4f46e5; }
        .cc-tag.revived { background:rgba(217,119,6,.14); color:#b45309; }
        .cc-angle { font-size:12.5px; color:#64748b; max-width:70ch; }
        .cc-actions { display:flex; gap:8px; align-items:center; }
        .cc-btn { font-size:12.5px; font-weight:600; padding:6px 13px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
        .cc-btn.primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
        .cc-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:22px; color:#94a3b8; font-size:13.5px; text-align:center; }
    </style>

    <div class="cc-list">
        @forelse ($candidates as $c)
            <div class="cc-card" wire:key="cand-{{ $c['id'] }}">
                <div class="cc-main">
                    <p class="cc-title">{{ $c['title'] ?: 'Untitled candidate' }}</p>
                    <div class="cc-meta">
                        @if ($c['directed'] ?? false) <span class="cc-tag directed">Directed</span> @endif
                        @if ($c['revived'] ?? false) <span class="cc-tag revived">Revival · {{ number_format($c['revived_impressions'] ?? 0) }} impr</span> @endif
                        @if (! empty($c['keyword'])) <span class="cc-tag">{{ $c['keyword'] }}</span> @endif
                        @if (! empty($c['silo'])) <span class="cc-tag">{{ $c['silo'] }}</span> @endif
                        @if (! empty($c['source'])) <span class="cc-tag">{{ $c['source'] }}</span> @endif
                    </div>
                    @if (! empty($c['angle']))
                        <div class="cc-angle">{{ $c['angle'] }}</div>
                    @endif
                </div>
                <div class="cc-actions">
                    @if ($this->can(\App\Security\Capability::GenerateContent))
                        <button class="cc-btn primary" wire:click="promote('{{ $c['id'] }}')" wire:loading.attr="disabled" wire:target="promote('{{ $c['id'] }}')">
                            <span wire:loading.remove wire:target="promote('{{ $c['id'] }}')">Generate</span>
                            <span wire:loading wire:target="promote('{{ $c['id'] }}')">Working…</span>
                        </button>
                    @endif
                    @if ($this->can(\App\Security\Capability::EditContent))
                        <button class="cc-btn" wire:click="dismiss('{{ $c['id'] }}')"
                                wire:confirm="Dismiss this candidate?">Dismiss</button>
                    @endif
                </div>
            </div>
        @empty
            <div class="cc-empty">No candidates waiting. New ones arrive as feeds are ingested.</div>
        @endforelse
    </div>
</x-filament-panels::page>
