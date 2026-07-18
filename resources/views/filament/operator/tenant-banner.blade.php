@php
    $banner = app(\App\Operator\ActiveTenant::class)->banner();
    $portfolioUrl = \App\Filament\Resources\SiteResource::getUrl('index');
@endphp

<div class="lp-tenant-banner">
    <style>
        .lp-tenant-banner { display:flex; align-items:center; gap:12px; padding:4px 6px; }
        .lp-tenant-banner .lp-tb-logo { height:34px; width:auto; max-width:150px; object-fit:contain; border-radius:6px; background:#fff; padding:2px 4px; box-shadow:0 1px 2px rgba(0,0,0,.08); }
        .lp-tenant-banner .lp-tb-badge { display:flex; align-items:center; justify-content:center; height:34px; width:34px; border-radius:8px; background:#f59e0b; color:#1a1a1a; font-weight:800; font-size:14px; }
        .lp-tenant-banner .lp-tb-meta { display:flex; flex-direction:column; line-height:1.15; }
        .lp-tenant-banner .lp-tb-eyebrow { font-size:9.5px; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; font-weight:700; }
        .lp-tenant-banner .lp-tb-name { font-size:15px; font-weight:800; color:#0f172a; }
        .lp-tenant-banner .lp-tb-switch { margin-left:6px; font-size:12px; font-weight:600; padding:5px 11px; border-radius:7px; border:1px solid rgba(148,163,184,.5); color:#334155; text-decoration:none; white-space:nowrap; }
        .lp-tenant-banner .lp-tb-switch:hover { background:rgba(148,163,184,.12); }
        .lp-tenant-banner.is-empty .lp-tb-name { color:#b45309; }
        @media (prefers-color-scheme: dark) {
            .lp-tenant-banner .lp-tb-name { color:#f1f5f9; }
            .lp-tenant-banner .lp-tb-switch { color:#cbd5e1; border-color:rgba(148,163,184,.35); }
        }
        .fi-topbar :is(.dark) .lp-tenant-banner .lp-tb-name { color:#f1f5f9; }
    </style>

    @if ($banner['has'])
        @if ($banner['logo_url'])
            <img class="lp-tb-logo" src="{{ $banner['logo_url'] }}" alt="{{ $banner['name'] }} logo">
        @else
            <span class="lp-tb-badge">{{ mb_strtoupper(mb_substr($banner['name'], 0, 1)) }}</span>
        @endif
        <div class="lp-tb-meta">
            <span class="lp-tb-eyebrow">Working on</span>
            <span class="lp-tb-name">{{ $banner['name'] }}</span>
        </div>
        <a class="lp-tb-switch" href="{{ $portfolioUrl }}" wire:navigate>Switch tenant</a>
    @else
        <div class="lp-tenant-banner is-empty" style="padding:0">
            <div class="lp-tb-meta">
                <span class="lp-tb-eyebrow">No tenant selected</span>
                <span class="lp-tb-name">Choose one to start</span>
            </div>
            <a class="lp-tb-switch" href="{{ $portfolioUrl }}" wire:navigate>Choose tenant</a>
        </div>
    @endif
</div>
