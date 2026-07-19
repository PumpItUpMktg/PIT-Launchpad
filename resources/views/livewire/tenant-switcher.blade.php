@php
    $banner = $this->banner;
    $portfolioUrl = \App\Filament\Resources\SiteResource::getUrl('index');
    $currentId = app(\App\Operator\ActiveTenant::class)->id();
@endphp

<div class="lp-sw" x-data="{ open: false, q: '' }" @keydown.escape.window="open = false">
    <style>
        .lp-sw { position:relative; }
        [x-cloak] { display:none !important; }
        .lp-sw-trigger { display:flex; align-items:center; gap:11px; padding:4px 8px; border:1px solid transparent; border-radius:9px; background:none; cursor:pointer; }
        .lp-sw-trigger.is-btn:hover { background:rgba(148,163,184,.1); border-color:rgba(148,163,184,.25); }
        .lp-sw-trigger.is-static { cursor:default; }
        .lp-sw-logo { height:32px; width:auto; max-width:140px; object-fit:contain; border-radius:6px; background:#fff; padding:2px 4px; box-shadow:0 1px 2px rgba(0,0,0,.08); }
        .lp-sw-badge { display:flex; align-items:center; justify-content:center; height:32px; width:32px; border-radius:8px; background:#f59e0b; color:#1a1a1a; font-weight:800; font-size:14px; }
        .lp-sw-meta { display:flex; flex-direction:column; line-height:1.15; text-align:left; }
        .lp-sw-eyebrow { font-size:9.5px; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; font-weight:700; }
        .lp-sw-name { font-size:15px; font-weight:800; color:#0f172a; }
        .lp-sw-chev { color:#94a3b8; width:14px; height:14px; transition:transform .15s; }
        .lp-sw[x-data] .lp-sw-chev.open { transform:rotate(180deg); }
        .lp-sw-panel { position:absolute; top:calc(100% + 6px); left:0; z-index:50; width:270px; max-height:60vh; overflow:auto; background:#fff; border:1px solid rgba(148,163,184,.3); border-radius:11px; box-shadow:0 8px 28px rgba(15,23,42,.16); padding:6px; }
        .lp-sw-search { width:100%; box-sizing:border-box; font-size:13px; padding:7px 10px; border:1px solid rgba(148,163,184,.4); border-radius:7px; margin-bottom:6px; }
        .lp-sw-item { display:flex; align-items:center; gap:9px; width:100%; text-align:left; padding:8px 9px; border:none; background:none; border-radius:7px; cursor:pointer; font-size:13.5px; color:#334155; }
        .lp-sw-item:hover { background:rgba(148,163,184,.12); }
        .lp-sw-item.current { font-weight:700; color:#0f172a; }
        .lp-sw-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; flex:none; }
        .lp-sw-dot.hidden { visibility:hidden; }
        .lp-sw-foot { border-top:1px solid rgba(148,163,184,.25); margin-top:5px; padding-top:5px; }
        .lp-sw-port { display:block; padding:8px 9px; font-size:13px; font-weight:600; color:#2563eb; text-decoration:none; border-radius:7px; }
        .lp-sw-port:hover { background:rgba(37,99,235,.08); }
        .lp-sw.is-empty .lp-sw-name { color:#b45309; }
        @media (prefers-color-scheme: dark) {
            .lp-sw-name { color:#f1f5f9; }
            .lp-sw-panel { background:#151b24; border-color:rgba(148,163,184,.25); }
            .lp-sw-item { color:#cbd5e1; } .lp-sw-item.current { color:#f1f5f9; }
        }
        .fi-topbar :is(.dark) .lp-sw-name { color:#f1f5f9; }
    </style>

    @if (! $banner['has'])
        {{-- No working tenant yet — a prompt straight to the picker. --}}
        <div class="lp-sw is-empty">
            <button type="button" class="lp-sw-trigger is-btn" @click="open = !open">
                <span class="lp-sw-badge">?</span>
                <span class="lp-sw-meta"><span class="lp-sw-eyebrow">No tenant selected</span><span class="lp-sw-name">Choose one to start</span></span>
            </button>
        </div>
    @else
        <button type="button" class="lp-sw-trigger {{ $this->single ? 'is-static' : 'is-btn' }}"
            @if (! $this->single) @click="open = !open" @endif>
            @if ($banner['logo_url'])
                <img class="lp-sw-logo" src="{{ $banner['logo_url'] }}" alt="{{ $banner['name'] }} logo">
            @else
                <span class="lp-sw-badge">{{ mb_strtoupper(mb_substr($banner['name'], 0, 1)) }}</span>
            @endif
            <span class="lp-sw-meta">
                <span class="lp-sw-eyebrow">Working on</span>
                <span class="lp-sw-name">{{ $banner['name'] }}</span>
            </span>
            @unless ($this->single)
                <svg class="lp-sw-chev" :class="{ 'open': open }" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
            @endunless
        </button>
    @endif

    @unless ($this->single)
        <div class="lp-sw-panel" x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.left>
            @if ($this->searchable)
                <input type="text" class="lp-sw-search" placeholder="Search tenants…" x-model="q" @click.stop>
            @endif
            @foreach ($this->sites as $site)
                <button type="button" class="lp-sw-item {{ $site->id === $currentId ? 'current' : '' }}"
                    wire:click="switchTenant('{{ $site->id }}')"
                    x-show="q === '' || @js(mb_strtolower($site->brand_name ?? '')).includes(q.toLowerCase())">
                    <span class="lp-sw-dot {{ $site->id === $currentId ? '' : 'hidden' }}"></span>
                    {{ $site->brand_name ?: 'Untitled tenant' }}
                </button>
            @endforeach
            <div class="lp-sw-foot">
                <a class="lp-sw-port" href="{{ $portfolioUrl }}" wire:navigate>Go to Portfolio →</a>
            </div>
        </div>
    @endunless
</div>
