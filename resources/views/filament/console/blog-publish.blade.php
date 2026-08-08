<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')

    @php $items = $this->publishing; @endphp

    <style>
        .pb-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:12px; }
        .pb-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; padding:13px 15px; display:flex; flex-direction:column; gap:9px; align-items:stretch; }
        .pb-thumb { width:100%; aspect-ratio:16/9; border-radius:8px; object-fit:cover; background:rgba(148,163,184,.15); }
        .pb-main { min-width:0; display:flex; flex-direction:column; gap:4px; }
        .pb-title { font-size:14.5px; font-weight:650; margin:0; }
        .pb-state { font-size:12px; color:#94a3b8; }
        .pb-state.stalled { color:#dc2626; font-weight:600; }
        .pb-meta { display:flex; gap:6px; flex-wrap:wrap; margin-top:2px; }
        .pb-tag { font-size:11px; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.14); color:#475569; }
        .pb-tag.town { background:rgba(217,119,6,.13); color:#b45309; }
        .pb-btn { font-size:12.5px; font-weight:600; padding:7px 15px; border-radius:8px; cursor:pointer; border:1px solid #4f46e5; background:#4f46e5; color:#fff; }
        .pb-inflight { font-size:12px; color:#4f46e5; }
        .pb-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:22px; color:#94a3b8; font-size:13.5px; text-align:center; }
    </style>

    <p style="color:#94a3b8; font-size:13px; margin:0 0 4px;">Approved posts, ready to publish. Publishing pushes straight to WordPress. Live posts appear under Published.</p>

    <div class="pb-list">
        @forelse ($items as $p)
            <div class="pb-card" wire:key="pub-{{ $p['id'] }}">
                @if (! empty($p['image']))
                    <img class="pb-thumb" src="{{ $p['image'] }}" alt="" loading="lazy">
                @endif
                <div class="pb-main">
                    <p class="pb-title">{{ $p['title'] ?: 'Untitled post' }}</p>
                    <div class="pb-state {{ ($p['stalled'] ?? false) ? 'stalled' : '' }}">
                        {{ $p['state'] ?? 'ready' }}{{ ($p['stalled'] ?? false) ? ' — stalled' : '' }}
                    </div>
                    @if (! empty($p['silo']) || ! empty($p['keyword']) || ! empty($p['towns']))
                        <div class="pb-meta">
                            @if (! empty($p['silo'])) <span class="pb-tag">{{ $p['silo'] }}</span> @endif
                            @if (! empty($p['keyword'])) <span class="pb-tag">{{ $p['keyword'] }}</span> @endif
                            @foreach (($p['towns'] ?? []) as $town) <span class="pb-tag town">📍 {{ $town }}</span> @endforeach
                        </div>
                    @endif
                </div>
                @if (($p['state'] ?? '') === 'queued to publish' || ($p['stalled'] ?? false))
                    @if ($this->can(\App\Security\Capability::PublishContent))
                        <button class="pb-btn" wire:click="publish('{{ $p['id'] }}')"
                                wire:loading.attr="disabled" wire:target="publish('{{ $p['id'] }}')">
                            <span wire:loading.remove wire:target="publish('{{ $p['id'] }}')">Publish</span>
                            <span wire:loading wire:target="publish('{{ $p['id'] }}')">Publishing…</span>
                        </button>
                    @endif
                @else
                    <span class="pb-inflight">In progress…</span>
                @endif
                @include('filament.console.partials.swap-photo', ['id' => $p['id']])
            </div>
        @empty
            <div class="pb-empty">Nothing ready to publish. Approve drafts in Review to queue them here.</div>
        @endforelse
    </div>
</x-filament-panels::page>
