<x-filament-panels::page>
    @php $p = $this->preview; @endphp

    @if ($p === null)
        <div style="border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:22px; color:#94a3b8; font-size:13.5px; text-align:center;">
            That post isn’t available for the active tenant.
            <a href="{{ \App\Filament\Console\Pages\BlogApproved::getUrl() }}" style="color:#4f46e5; font-weight:600;">Back to Approved</a>
        </div>
    @else
        <style>
            .pv-wrap { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:22px; align-items:start; }
            @media (max-width: 900px) { .pv-wrap { grid-template-columns:1fr; } }
            .pv-hero { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:12px; background:rgba(148,163,184,.12); display:block; margin-bottom:16px; }
            .pv-title { font-size:24px; font-weight:760; line-height:1.25; margin:0 0 10px; }
            .pv-body { font-size:15px; line-height:1.7; color:inherit; }
            .pv-body h2 { font-size:20px; font-weight:700; margin:22px 0 8px; }
            .pv-body h3 { font-size:17px; font-weight:700; margin:18px 0 6px; }
            .pv-body p { margin:0 0 14px; }
            .pv-body ul, .pv-body ol { margin:0 0 14px 20px; }
            .pv-body a { color:#4f46e5; text-decoration:underline; }
            .pv-body img { max-width:100%; border-radius:10px; }
            .pv-panel { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; }
            .pv-panel h4 { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#94a3b8; margin:0 0 8px; }
            .pv-row { font-size:13px; margin:0 0 8px; word-break:break-word; }
            .pv-row .l { color:#94a3b8; display:block; font-size:11px; }
            .pv-link { font-size:12.5px; margin:0 0 6px; word-break:break-word; }
            .pv-link .t { font-weight:600; }
            .pv-link .u { color:#94a3b8; }
            .pv-chip { display:inline-block; font-size:11px; padding:2px 8px; border-radius:99px; background:rgba(217,119,6,.13); color:#b45309; margin:0 4px 4px 0; }
            .pv-actions { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
            .pv-btn { font-size:13px; font-weight:600; padding:8px 16px; border-radius:9px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; text-decoration:none; display:inline-flex; align-items:center; }
            .pv-btn.green { background:#16a34a; border-color:#16a34a; color:#fff; }
        </style>

        <div class="pv-actions">
            <a class="pv-btn" href="{{ \App\Filament\Console\Pages\BlogApproved::getUrl() }}">← Approved</a>
            @if ($this->can(\App\Security\Capability::ApproveContent))
                <button class="pv-btn green" wire:click="release" wire:loading.attr="disabled" wire:target="release">
                    <span wire:loading.remove wire:target="release">Send to Publish</span>
                    <span wire:loading wire:target="release">Sending…</span>
                </button>
            @endif
            @if ($this->can(\App\Security\Capability::EditContent))
                <button class="pv-btn" wire:click="sendBack">Back to Review</button>
            @endif
        </div>

        <div class="pv-wrap">
            <div>
                @if (! empty($p['image']))
                    <img src="{{ $p['image'] }}" alt="" class="pv-hero" decoding="async">
                @endif
                <h1 class="pv-title">{{ $p['title'] ?: 'Untitled post' }}</h1>

                @if (trim($p['body']) !== '')
                    <div class="pv-body">{!! $p['body'] !!}</div>
                @else
                    <p style="color:#94a3b8; font-size:13.5px;">No body drafted yet.</p>
                @endif
            </div>

            <aside style="display:flex; flex-direction:column; gap:16px;">
                {{-- SEO --}}
                <div class="pv-panel">
                    <h4>SEO — what search sees</h4>
                    <div class="pv-row"><span class="l">Title tag</span>{{ $p['seo']['title'] ?: '—' }}</div>
                    <div class="pv-row"><span class="l">Meta description</span>{{ $p['seo']['meta_description'] ?: '—' }}</div>
                    <div class="pv-row"><span class="l">Slug</span>/{{ ltrim((string) $p['seo']['slug'], '/') ?: '—' }}</div>
                </div>

                {{-- Targeting --}}
                @if (! empty($p['keyword']) || ! empty($p['silo']) || ! empty($p['towns']))
                    <div class="pv-panel">
                        <h4>Targeting</h4>
                        @if (! empty($p['silo'])) <div class="pv-row"><span class="l">Silo</span>{{ $p['silo'] }}</div> @endif
                        @if (! empty($p['keyword'])) <div class="pv-row"><span class="l">Keyword</span>{{ $p['keyword'] }}</div> @endif
                        @if (! empty($p['towns']))
                            <div class="pv-row"><span class="l">Towns covered</span>
                                @foreach ($p['towns'] as $town) <span class="pv-chip">📍 {{ $town }}</span> @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Internal links --}}
                <div class="pv-panel">
                    <h4>Internal links this page makes</h4>
                    @forelse ($p['outbound_links'] as $link)
                        <div class="pv-link"><span class="t">{{ $link['text'] }}</span><br><span class="u">{{ $link['href'] }}</span></div>
                    @empty
                        <div class="pv-row" style="color:#94a3b8;">None found in the body.</div>
                    @endforelse
                </div>

                <div class="pv-panel">
                    <h4>Linked from ({{ count($p['inbound_links']) }})</h4>
                    @forelse ($p['inbound_links'] as $link)
                        <div class="pv-link"><span class="t">{{ $link['title'] ?: 'Untitled' }}</span><br><span class="u">/{{ ltrim((string) $link['slug'], '/') }}</span></div>
                    @empty
                        <div class="pv-row" style="color:#94a3b8;">No other pages link here yet.</div>
                    @endforelse
                </div>
            </aside>
        </div>
    @endif
</x-filament-panels::page>
