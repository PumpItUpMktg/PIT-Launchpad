<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')

    @php $board = $this->board; @endphp

    <style>
        /* 3-up responsive grid */
        .rc-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:14px; }
        .pl-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:10px; }
        /* Rich post card */
        .rc-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:9px; }
        .rc-thumb { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:9px; background:rgba(148,163,184,.15); }
        .rc-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start; }
        .rc-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .rc-chip { font-size:11px; font-weight:600; padding:2px 9px; border-radius:99px; background:rgba(148,163,184,.16); color:#475569; }
        .rc-chip.silo { background:rgba(99,102,241,.12); color:#4f46e5; }
        .rc-chip.town { background:rgba(217,119,6,.13); color:#b45309; }
        .rc-chip.good { background:rgba(22,163,74,.15); color:#15803d; }
        .rc-chip.muted { background:rgba(148,163,184,.16); color:#94a3b8; }
        .rc-title { font-size:15px; font-weight:680; }
        .rc-url { font-size:12px; color:#4f46e5; text-decoration:none; word-break:break-all; }
        .rc-sub { font-size:11.5px; color:#94a3b8; }
        .rc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:10px; margin-top:3px; }
        .rc-stat { border:1px solid rgba(148,163,184,.25); border-radius:9px; padding:8px 11px; }
        .rc-k { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; }
        .rc-v { font-size:13.5px; font-weight:600; margin-top:2px; }
        .rc-muted { color:#94a3b8; font-weight:500; }
        .rc-delta { font-size:11px; color:#15803d; }
        .rc-block { margin-top:2px; }
        .rc-queries { display:flex; gap:7px; flex-wrap:wrap; margin-top:4px; }
        .rc-q { font-size:11.5px; padding:2px 8px; border-radius:6px; background:rgba(148,163,184,.12); color:#475569; }
        .rc-q em { color:#94a3b8; font-style:normal; }
        .rc-links { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:4px; }
        .rc-linkcol { display:flex; flex-direction:column; gap:3px; }
        .rc-link { font-size:12px; color:#4f46e5; text-decoration:none; }
        .rc-actions { display:flex; gap:8px; margin-top:4px; }
        .rc-btn { font-size:12px; font-weight:600; padding:6px 13px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; text-decoration:none; }
        /* Simple pages list */
        .pl-h { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:22px 0 9px; }
        .pl-row { border:1px solid rgba(148,163,184,.3); border-radius:10px; padding:9px 13px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
        .pl-main { flex:1 1 300px; min-width:0; }
        .pl-title { font-size:14px; font-weight:600; margin:0; }
        .pl-meta { font-size:11.5px; color:#94a3b8; display:flex; gap:8px; flex-wrap:wrap; }
        .pl-link { font-size:12.5px; font-weight:600; color:#4f46e5; text-decoration:none; }
        .rc-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:20px; color:#94a3b8; font-size:13.5px; text-align:center; }
    </style>

    <div class="pl-h" style="margin-top:4px;">Blog posts ({{ count($board['posts']) }})</div>
    @if (count($board['posts']) > 0)
        <div class="rc-grid">
            @foreach ($board['posts'] as $post)
                @include('filament.console.partials.post-card', ['post' => $post])
            @endforeach
        </div>
    @else
        <div class="rc-empty">No live blog posts{{ ($county ?? null) || ($siloId ?? null) ? ' for this filter' : '' }} yet.</div>
    @endif

    <div class="pl-h">Site pages ({{ count($board['pages']) }})</div>
    <div class="pl-grid">
    @forelse ($board['pages'] as $item)
        <div class="pl-row" wire:key="livepage-{{ $item['id'] }}">
            <div class="pl-main">
                <p class="pl-title">{{ $item['title'] ?: 'Untitled' }}</p>
                <div class="pl-meta">
                    <span>Live</span>
                    @if (! empty($item['page_type'])) <span>{{ $item['page_type'] }}</span> @endif
                    @if (! empty($item['silo'])) <span>{{ $item['silo'] }}</span> @endif
                    @if (! empty($item['published_at'])) <span>{{ $item['published_at'] }}</span> @endif
                </div>
            </div>
            @if (! empty($item['url']))
                <a class="pl-link" href="{{ $item['url'] }}" target="_blank" rel="noopener">View ↗</a>
            @endif
            @if ($this->can(\App\Security\Capability::PublishContent))
                <button class="rc-btn" wire:click="repush('{{ $item['id'] }}')"
                        wire:loading.attr="disabled" wire:target="repush('{{ $item['id'] }}')">Re-sync</button>
            @endif
        </div>
    @empty
        <div class="rc-empty">No live site pages for this filter.</div>
    @endforelse
    </div>
</x-filament-panels::page>
