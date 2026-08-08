<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')

    @php $candidates = $this->candidates; @endphp

    <style>
        .cc-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:12px; }
        .cc-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; padding:13px 15px; display:flex; flex-direction:column; align-items:stretch; gap:9px; }
        .cc-top { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .cc-main { flex:1 1 320px; min-width:0; display:flex; flex-direction:column; gap:5px; }
        .cc-titlerow { display:flex; gap:10px; align-items:flex-start; justify-content:space-between; }
        .cc-title { font-size:14.5px; font-weight:650; margin:0; }
        .cc-meta { display:flex; gap:8px; flex-wrap:wrap; font-size:11.5px; color:#94a3b8; }
        .cc-tag { display:inline-flex; align-items:center; gap:5px; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.14); }
        .cc-tag.silo { background:rgba(79,70,229,.1); color:#4f46e5; }
        .cc-tag.source { background:rgba(13,148,136,.12); color:#0f766e; }
        .cc-tag.date { background:rgba(100,116,139,.12); color:#475569; }
        .cc-tag.directed { background:rgba(79,70,229,.13); color:#4f46e5; }
        .cc-tag.revived { background:rgba(217,119,6,.14); color:#b45309; }
        .cc-score { flex:none; display:flex; flex-direction:column; align-items:center; justify-content:center; min-width:52px; padding:4px 8px; border-radius:9px; background:rgba(22,163,74,.12); color:#15803d; }
        .cc-score .n { font-size:17px; font-weight:800; line-height:1; }
        .cc-score .l { font-size:9px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.8; }
        .cc-score.mid { background:rgba(217,119,6,.13); color:#b45309; }
        .cc-score.low { background:rgba(100,116,139,.14); color:#475569; }
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
                    {{-- Top row: Silo · Source · Date --}}
                    <div class="cc-top">
                        @if (! empty($c['silo'])) <span class="cc-tag silo">{{ $c['silo'] }}</span> @endif
                        @if (! empty($c['source'])) <span class="cc-tag source">{{ $c['source'] }}</span> @endif
                        @if (! empty($c['date'])) <span class="cc-tag date">🗓 {{ $c['date'] }}</span> @endif
                    </div>
                    <div class="cc-titlerow">
                        <p class="cc-title">{{ $c['title'] ?: 'Untitled candidate' }}</p>
                        @if (($c['score'] ?? null) !== null)
                            @php $s = (float) $c['score']; @endphp
                            <div class="cc-score {{ $s >= 0.7 ? '' : ($s >= 0.4 ? 'mid' : 'low') }}">
                                <span class="n">{{ number_format($s, 2) }}</span>
                                <span class="l">Score</span>
                            </div>
                        @endif
                    </div>
                    <div class="cc-meta">
                        @if ($c['directed'] ?? false) <span class="cc-tag directed">Directed</span> @endif
                        @if ($c['revived'] ?? false) <span class="cc-tag revived">Revival · {{ number_format($c['revived_impressions'] ?? 0) }} impr</span> @endif
                        @if (! empty($c['keyword'])) <span class="cc-tag">{{ $c['keyword'] }}</span> @endif
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
