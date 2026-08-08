<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')

    @php $review = $this->review; @endphp

    <style>
        .rv-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:12px; }
        .rv-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; padding:13px 15px; display:flex; flex-direction:column; gap:9px; align-items:stretch; }
        .rv-thumb { width:100%; aspect-ratio:16/9; border-radius:8px; object-fit:cover; background:rgba(148,163,184,.15); }
        .rv-main { min-width:0; display:flex; flex-direction:column; gap:5px; }
        .rv-title { font-size:14.5px; font-weight:650; margin:0; }
        .rv-meta { display:flex; gap:8px; flex-wrap:wrap; font-size:11.5px; color:#94a3b8; }
        .rv-tag { display:inline-flex; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.14); }
        .rv-tag.writing { background:rgba(79,70,229,.13); color:#4f46e5; }
        .rv-tag.bad { background:rgba(220,38,38,.12); color:#dc2626; }
        .rv-tag.town { background:rgba(217,119,6,.13); color:#b45309; }
        .rv-tag.silo { background:rgba(79,70,229,.1); color:#4f46e5; }
        .rv-tag.source { background:rgba(13,148,136,.12); color:#0f766e; }
        .rv-tag.date { background:rgba(100,116,139,.12); color:#475569; }
        .rv-thumb.empty { display:flex; flex-direction:column; gap:2px; align-items:center; justify-content:center; color:#4f46e5; font-size:12px; font-weight:600; border:1px dashed rgba(79,70,229,.45); background:rgba(79,70,229,.04); cursor:pointer; }
        .rv-thumb.empty .sub { font-size:10.5px; font-weight:500; color:#94a3b8; }
        .rv-top { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-bottom:2px; }
        .rv-excerpt { font-size:12.5px; color:#64748b; max-width:80ch; }
        .rv-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .rv-btn { font-size:12.5px; font-weight:600; padding:6px 13px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
        .rv-btn.primary { background:#16a34a; border-color:#16a34a; color:#fff; }
        .rv-btn.danger { color:#dc2626; border-color:rgba(220,38,38,.4); }
        .rv-reject { display:flex; gap:8px; align-items:center; margin-top:8px; width:100%; }
        .rv-reject input { flex:1 1 auto; font-size:13px; padding:6px 10px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
        .rv-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:22px; color:#94a3b8; font-size:13.5px; text-align:center; }
    </style>

    <div class="rv-list">
        @forelse ($review as $r)
            <div class="rv-card" wire:key="rev-{{ $r['id'] }}">
                @if (! empty($r['image']))
                    <img class="rv-thumb" src="{{ $r['image'] }}" alt="" loading="lazy">
                @elseif ($this->can(\App\Security\Capability::EditContent))
                    <div class="rv-thumb empty" wire:click="startSwap('{{ $r['id'] }}')">
                        <span>⬆ Upload photo</span>
                        <span class="sub">Image generates at publish</span>
                    </div>
                @else
                    <div class="rv-thumb empty" style="cursor:default; color:#94a3b8; border-color:rgba(148,163,184,.4); background:transparent;">
                        <span class="sub">Image generates at publish</span>
                    </div>
                @endif
                <div class="rv-main">
                    {{-- Top row: Silo · Source · Date --}}
                    <div class="rv-top">
                        @if (! empty($r['silo'])) <span class="rv-tag silo">{{ $r['silo'] }}</span> @endif
                        @if (! empty($r['source'])) <span class="rv-tag source">{{ $r['source'] }}</span> @endif
                        @if (! empty($r['date'])) <span class="rv-tag date">🗓 {{ $r['date'] }}</span> @endif
                    </div>
                    <p class="rv-title">{{ $r['title'] ?: 'Untitled draft' }}</p>
                    <div class="rv-meta">
                        @php $state = $r['state'] ?? $r['status']; @endphp
                        <span class="rv-tag {{ $state === 'writing' ? 'writing' : (in_array($state, ['draft_failed','render_failed','publish_failed','undrafted'], true) ? 'bad' : '') }}">{{ str_replace('_', ' ', (string) $state) }}</span>
                        @if (! empty($r['keyword'])) <span class="rv-tag">{{ $r['keyword'] }}</span> @endif
                        @foreach (($r['towns'] ?? []) as $town) <span class="rv-tag town">📍 {{ $town }}</span> @endforeach
                    </div>
                    @if (! empty($r['draft_error']))
                        <div class="rv-excerpt" style="color:#dc2626;">{{ $r['draft_error'] }}</div>
                    @elseif (! empty($r['excerpt']))
                        <div class="rv-excerpt">{{ $r['excerpt'] }}</div>
                    @endif

                    @if ($rejectingId === $r['id'])
                        <div class="rv-reject">
                            <input type="text" wire:model="rejectReason" placeholder="Reason (optional)" wire:keydown.enter="reject">
                            <button class="rv-btn danger" wire:click="reject">Confirm reject</button>
                            <button class="rv-btn" wire:click="cancelReject">Cancel</button>
                        </div>
                    @endif
                </div>
                <div class="rv-actions">
                    @php $hasDraft = $r['has_draft'] ?? false; @endphp
                    @if ($hasDraft && $this->can(\App\Security\Capability::ApproveContent))
                        <button class="rv-btn primary" wire:click="approve('{{ $r['id'] }}')"
                                wire:loading.attr="disabled" wire:target="approve('{{ $r['id'] }}')">Approve</button>
                    @endif
                    @if ($this->can(\App\Security\Capability::GenerateContent))
                        <button class="rv-btn {{ $hasDraft ? '' : 'primary' }}" wire:click="regenerate('{{ $r['id'] }}')"
                                wire:loading.attr="disabled" wire:target="regenerate('{{ $r['id'] }}')">
                            <span wire:loading.remove wire:target="regenerate('{{ $r['id'] }}')">{{ $hasDraft ? 'Regenerate' : 'Generate' }}</span>
                            <span wire:loading wire:target="regenerate('{{ $r['id'] }}')">Working…</span>
                        </button>
                    @endif
                    @if ($this->can(\App\Security\Capability::EditContent) && $rejectingId !== $r['id'])
                        <button class="rv-btn danger" wire:click="startReject('{{ $r['id'] }}')">Reject</button>
                    @endif
                </div>
                @include('filament.console.partials.swap-photo', ['id' => $r['id']])
            </div>
        @empty
            <div class="rv-empty">Nothing waiting for review. Generated drafts land here.</div>
        @endforelse
    </div>
</x-filament-panels::page>
