<x-lp.shell
    variant="table"
    eyebrow="System"
    title="Brand"
    lede="The tenant's visual identity — logo, the resolved look, and the style variation that paints every page. Pick a variation, then push it to WordPress as a theme.json style. Header & footer chrome is a separate push (Recover).">

    @php($board = $this->board)

    <style>
        .br-id { display:flex; gap:16px; align-items:center; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:18px; }
        .br-logo { width:64px; height:64px; border-radius:10px; object-fit:contain; background:var(--paper); border:1px solid var(--line); flex:none; }
        .br-logo.dark { background:#0f151d; }
        .br-logo-none { width:64px; height:64px; border-radius:10px; background:var(--paper); border:1px dashed var(--line); display:flex; align-items:center; justify-content:center; color:var(--ink-soft); font-size:10px; text-align:center; flex:none; }
        .br-id-main { min-width:0; }
        .br-name { font-size:18px; font-weight:800; color:var(--ink); }
        .br-look { display:flex; gap:6px; align-items:center; margin-top:8px; flex-wrap:wrap; }
        .br-swatch { width:22px; height:22px; border-radius:6px; border:1px solid rgba(0,0,0,.12); }
        .br-look-meta { font-size:12px; color:var(--ink-soft); margin-left:6px; }
        .br-active { margin-left:auto; text-align:right; flex:none; }
        .br-active .l { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); }
        .br-active .v { font-size:14px; font-weight:700; color:var(--teal-deep); }
        .br-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }
        .br-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:14px; cursor:pointer; text-align:left; width:100%; transition:border-color .12s; }
        .br-card:hover { border-color:var(--teal-deep); }
        .br-card.chosen { border-color:var(--teal-deep); box-shadow:0 0 0 1px var(--teal-deep) inset; }
        .br-card .head { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px; }
        .br-card .name { font-weight:700; font-size:13.5px; color:var(--ink); }
        .br-swatches { display:flex; gap:3px; margin-bottom:8px; }
        .br-swatches span { flex:1; height:26px; border-radius:4px; border:1px solid rgba(0,0,0,.10); }
        .br-blurb { font-size:11.5px; color:var(--ink-soft); line-height:1.4; }
        .br-warn { background:var(--paper); border:1px solid var(--line); border-left:3px solid var(--amber); border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:12.5px; color:var(--ink-soft); }
    </style>

    @if ($board === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to manage its brand.
        </x-lp.empty>
    @else
        <div class="br-id">
            @if ($board['has_logo'])
                <img src="{{ $board['logo_url'] }}" alt="{{ $board['brand_name'] }} logo" class="br-logo {{ $board['header_tone'] === 'dark' ? 'dark' : '' }}">
            @else
                <div class="br-logo-none">No logo</div>
            @endif
            <div class="br-id-main">
                <div class="br-name">{{ $board['brand_name'] !== '' ? $board['brand_name'] : 'Unnamed tenant' }}</div>
                <div class="br-look">
                    <span class="br-swatch" style="background:{{ $board['look']['primary'] }}" title="Primary {{ $board['look']['primary'] }}"></span>
                    <span class="br-swatch" style="background:{{ $board['look']['accent'] }}" title="Accent {{ $board['look']['accent'] }}"></span>
                    <span class="br-look-meta">{{ $board['look']['heading_font'] }}</span>
                </div>
            </div>
            <div class="br-active">
                <div class="l">Active style</div>
                <div class="v">{{ $board['active_label'] }}</div>
                <div style="margin-top:6px">
                    <x-lp.chip :tone="$board['pushed'] ? 'good' : 'neutral'">{{ $board['pushed'] ? 'Pushed' : 'Not pushed' }}</x-lp.chip>
                </div>
            </div>
        </div>

        @if ($board['shadows_curated'])
            <div class="br-warn">
                Using <strong>your brand colors</strong> (from the logo) — this shadows the curated pick
                “{{ $board['curated_label'] }}”. Choose a curated variation below to switch back.
            </div>
        @endif

        @if (! $board['has_wp'])
            <div class="br-warn">Connect WordPress (Connections) before pushing — the style can be chosen now but not applied to the live site yet.</div>
        @endif

        <div class="br-grid">
            @foreach ($board['options'] as $opt)
                <button type="button" class="br-card {{ $opt['chosen'] ? 'chosen' : '' }}"
                    wire:click="chooseStyle('{{ $opt['key'] }}')"
                    wire:key="br-{{ $opt['key'] }}">
                    <div class="head">
                        <span class="name">{{ $opt['label'] }}</span>
                        @if ($opt['chosen'])
                            <x-lp.chip tone="good">Chosen</x-lp.chip>
                        @elseif ($opt['badge'])
                            <x-lp.chip :tone="$opt['recommended'] ? 'info' : 'neutral'">{{ $opt['badge'] }}</x-lp.chip>
                        @endif
                    </div>
                    <div class="br-swatches">
                        @foreach ($opt['swatches'] as $sw)
                            <span style="background:{{ $sw }}"></span>
                        @endforeach
                    </div>
                    <div class="br-blurb">{{ $opt['blurb'] }}</div>
                </button>
            @endforeach
        </div>
    @endif
</x-lp.shell>
