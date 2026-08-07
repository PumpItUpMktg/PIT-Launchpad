<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $board = $this->board; @endphp

    <style>
        .pl-section { display:flex; flex-direction:column; gap:9px; margin-bottom:18px; }
        .pl-h { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:0; }
        .pl-row { border:1px solid rgba(148,163,184,.32); border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .pl-main { flex:1 1 300px; min-width:0; display:flex; flex-direction:column; gap:3px; }
        .pl-title { font-size:14px; font-weight:600; margin:0; }
        .pl-meta { font-size:11.5px; color:#94a3b8; display:flex; gap:8px; flex-wrap:wrap; }
        .pl-tag { padding:1px 8px; border-radius:99px; background:rgba(22,163,74,.12); color:#15803d; }
        .pl-actions { display:flex; gap:8px; align-items:center; }
        .pl-link { font-size:12.5px; font-weight:600; color:#4f46e5; text-decoration:none; }
        .pl-btn { font-size:12px; font-weight:600; padding:5px 12px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
        .pl-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:18px; color:#94a3b8; font-size:13px; text-align:center; }
    </style>

    @foreach ([['key' => 'posts', 'label' => 'Blog posts'], ['key' => 'pages', 'label' => 'Site pages']] as $section)
        <div class="pl-section">
            <p class="pl-h">{{ $section['label'] }} ({{ count($board[$section['key']]) }})</p>
            @forelse ($board[$section['key']] as $item)
                <div class="pl-row" wire:key="live-{{ $item['id'] }}">
                    <div class="pl-main">
                        <p class="pl-title">{{ $item['title'] ?: 'Untitled' }}</p>
                        <div class="pl-meta">
                            <span class="pl-tag">Live</span>
                            @if (! empty($item['page_type'])) <span>{{ $item['page_type'] }}</span> @endif
                            @if (! empty($item['published_at'])) <span>{{ $item['published_at'] }}</span> @endif
                        </div>
                    </div>
                    <div class="pl-actions">
                        @if (! empty($item['url']))
                            <a class="pl-link" href="{{ $item['url'] }}" target="_blank" rel="noopener">View ↗</a>
                        @endif
                        @if ($this->can(\App\Security\Capability::PublishContent))
                            <button class="pl-btn" wire:click="repush('{{ $item['id'] }}')"
                                    wire:loading.attr="disabled" wire:target="repush('{{ $item['id'] }}')">Re-sync</button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="pl-empty">Nothing live in this section yet.</div>
            @endforelse
        </div>
    @endforeach
</x-filament-panels::page>
