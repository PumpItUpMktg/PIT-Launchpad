<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')

    @php
        $board = $this->board;
        $storefronts = collect($board['storefronts']);
        $hubs = $storefronts->filter(fn ($s) => $s['hub'])->values();
        $storefrontsWithTowns = $storefronts->filter(fn ($s) => count($s['towns']) > 0)->values();

        $tabs = [
            'blog' => ['Blog', count($board['blog'])],
            'core' => ['Core Pages', count($board['core'])],
            'service' => ['Service Pages', count($board['service'])],
            'storefront' => ['Storefront Pages', $hubs->count()],
            'location' => ['Location Pages', $storefronts->sum(fn ($s) => count($s['towns']))],
        ];

        // Resolve the active Location-tab storefront (default to the first with towns).
        $activeSfId = $activeStorefront ?? ($storefrontsWithTowns[0]['location_id'] ?? null);
        $activeSf = $storefrontsWithTowns->firstWhere('location_id', $activeSfId);
    @endphp

    <style>
        /* Tabs */
        .pt-tabs { display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid rgba(148,163,184,.3); margin:2px 0 16px; }
        .pt-tab { font-size:13.5px; font-weight:600; padding:9px 14px; border:0; background:transparent; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; display:inline-flex; align-items:center; gap:7px; }
        .pt-tab:hover { color:#4f46e5; }
        .pt-tab.on { color:#4f46e5; border-bottom-color:#4f46e5; }
        .pt-n { font-size:11px; font-weight:700; padding:1px 7px; border-radius:99px; background:rgba(148,163,184,.18); color:#64748b; }
        .pt-tab.on .pt-n { background:rgba(79,70,229,.13); color:#4f46e5; }
        /* Storefront sub-tabs (Location Pages) */
        .pt-subtabs { display:flex; gap:6px; flex-wrap:wrap; margin:0 0 14px; }
        .pt-sub { font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:8px; border:1px solid rgba(148,163,184,.35); background:transparent; color:#475569; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
        .pt-sub.on { background:rgba(79,70,229,.1); border-color:#4f46e5; color:#4f46e5; }
        .pt-badge { font-size:10px; font-weight:700; padding:1px 6px; border-radius:99px; background:rgba(217,119,6,.14); color:#b45309; }

        /* Card grid — capped at 3 across for readability, dropping to 2 then 1 on narrower screens. */
        .rc-cards { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; }
        @media (max-width: 1100px) { .rc-cards { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 720px) { .rc-cards { grid-template-columns:1fr; } }
        /* Rich card */
        .rc-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:9px; }
        .rc-thumb { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:9px; background:rgba(148,163,184,.15); }
        .rc-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start; }
        .rc-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .rc-chip { font-size:11px; font-weight:600; padding:2px 9px; border-radius:99px; background:rgba(148,163,184,.16); color:#475569; }
        .rc-chip.silo { background:rgba(99,102,241,.12); color:#4f46e5; }
        .rc-chip.town { background:rgba(217,119,6,.13); color:#b45309; }
        .rc-chip.good { background:rgba(22,163,74,.15); color:#15803d; }
        .rc-chip.muted { background:rgba(148,163,184,.16); color:#94a3b8; }
        .rc-chip.score { background:rgba(79,70,229,.12); color:#4f46e5; }
        .rc-chip.score.good { background:rgba(22,163,74,.15); color:#15803d; }
        .rc-chip.score.muted { background:rgba(148,163,184,.16); color:#94a3b8; }
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
        .rc-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:20px; color:#94a3b8; font-size:13.5px; text-align:center; }
    </style>

    {{-- Tab bar --}}
    <div class="pt-tabs">
        @foreach ($tabs as $key => [$label, $n])
            <button class="pt-tab {{ $activeTab === $key ? 'on' : '' }}" wire:click="showTab('{{ $key }}')" type="button">
                {{ $label }} <span class="pt-n">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    @php $filtered = ($county ?? null) || ($siloId ?? null); @endphp

    {{-- Blog --}}
    @if ($activeTab === 'blog')
        @if (count($board['blog']) > 0)
            <div class="rc-cards">
                @foreach ($board['blog'] as $post)
                    @include('filament.console.partials.post-card', ['post' => $post])
                @endforeach
            </div>
        @else
            <div class="rc-empty">No live blog posts{{ $filtered ? ' for this filter' : '' }} yet.</div>
        @endif

    {{-- Core Pages --}}
    @elseif ($activeTab === 'core')
        @if (count($board['core']) > 0)
            <div class="rc-cards">
                @foreach ($board['core'] as $post)
                    @include('filament.console.partials.post-card', ['post' => $post])
                @endforeach
            </div>
        @else
            <div class="rc-empty">No live core pages{{ $filtered ? ' for this filter' : '' }} yet.</div>
        @endif

    {{-- Service Pages --}}
    @elseif ($activeTab === 'service')
        @if (count($board['service']) > 0)
            <div class="rc-cards">
                @foreach ($board['service'] as $post)
                    @include('filament.console.partials.post-card', ['post' => $post])
                @endforeach
            </div>
        @else
            <div class="rc-empty">No live service pages{{ $filtered ? ' for this filter' : '' }} yet.</div>
        @endif

    {{-- Storefront Pages: the GMB / base-location hub pages --}}
    @elseif ($activeTab === 'storefront')
        @if ($hubs->count() > 0)
            <div class="rc-cards">
                @foreach ($hubs as $sf)
                    <div wire:key="sf-hub-{{ $sf['location_id'] }}">
                        <div class="rc-chips" style="margin-bottom:6px;">
                            <span class="rc-chip silo">🏬 {{ $sf['name'] }}</span>
                            @if ($sf['is_storefront']) <span class="pt-badge">Storefront</span> @endif
                            @if ($sf['gbp_linked']) <span class="pt-badge" style="background:rgba(22,163,74,.15); color:#15803d;">GBP linked</span> @endif
                        </div>
                        @include('filament.console.partials.post-card', ['post' => $sf['hub']])
                    </div>
                @endforeach
            </div>
        @else
            <div class="rc-empty">No live storefront hub pages{{ $filtered ? ' for this filter' : '' }} yet.</div>
        @endif

    {{-- Location Pages: one sub-tab per storefront, its town pages in cards --}}
    @elseif ($activeTab === 'location')
        @if ($storefrontsWithTowns->count() > 0)
            <div class="pt-subtabs">
                @foreach ($storefrontsWithTowns as $sf)
                    <button class="pt-sub {{ $activeSfId === $sf['location_id'] ? 'on' : '' }}"
                            wire:click="$set('activeStorefront', '{{ $sf['location_id'] }}')" type="button">
                        {{ $sf['name'] }}
                        @if ($sf['is_storefront']) <span class="pt-badge">🏬</span> @endif
                        <span class="pt-n">{{ count($sf['towns']) }}</span>
                    </button>
                @endforeach
            </div>

            @if ($activeSf)
                <div class="rc-cards">
                    @foreach ($activeSf['towns'] as $post)
                        @include('filament.console.partials.post-card', ['post' => $post])
                    @endforeach
                </div>
            @else
                <div class="rc-empty">Pick a storefront above to see its town pages.</div>
            @endif
        @else
            <div class="rc-empty">No live location (town) pages{{ $filtered ? ' for this filter' : '' }} yet.</div>
        @endif
    @endif
</x-filament-panels::page>
