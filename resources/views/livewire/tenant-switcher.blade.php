@php
    $banner = $this->banner;
    $lobbyUrl = \App\Filament\Pages\Lobby::getUrl();
@endphp

{{--
    The locked-tenant chip. NO switcher dropdown (tenant-lock remediation, shape E): the header shows the
    CURRENT tenant only, plus "Exit site". Changing tenant is Exit site → Lobby → enter — deliberate
    friction, so no page carries other tenants' names in its chrome.
--}}
<div class="lp-sw">
    <style>
        .lp-sw { display:flex; align-items:center; gap:10px; }
        .lp-sw-chip { display:flex; align-items:center; gap:11px; padding:4px 8px; border-radius:9px; }
        .lp-sw-logo { height:32px; width:auto; max-width:140px; object-fit:contain; border-radius:6px; background:#fff; padding:2px 4px; box-shadow:0 1px 2px rgba(0,0,0,.08); }
        .lp-sw-badge { display:flex; align-items:center; justify-content:center; height:32px; width:32px; border-radius:8px; background:#f59e0b; color:#1a1a1a; font-weight:800; font-size:14px; }
        .lp-sw-meta { display:flex; flex-direction:column; line-height:1.15; text-align:left; }
        .lp-sw-eyebrow { font-size:9.5px; text-transform:uppercase; letter-spacing:.09em; color:#94a3b8; font-weight:700; }
        .lp-sw-name { font-size:15px; font-weight:800; color:#0f172a; }
        .lp-sw-exit { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:#64748b; background:none; border:1px solid rgba(148,163,184,.35); border-radius:8px; padding:5px 10px; cursor:pointer; }
        .lp-sw-exit:hover { background:rgba(148,163,184,.12); border-color:rgba(148,163,184,.5); color:#334155; }
        .lp-sw-exit svg { width:13px; height:13px; }
        .lp-sw.is-empty .lp-sw-name { color:#b45309; }
        @media (prefers-color-scheme: dark) {
            .lp-sw-name { color:#f1f5f9; }
            .lp-sw-exit { color:#cbd5e1; }
        }
        .fi-topbar :is(.dark) .lp-sw-name { color:#f1f5f9; }
    </style>

    @if (! $banner['has'])
        {{-- No working tenant (the gate normally prevents this) — a prompt straight to the Lobby. --}}
        <a href="{{ $lobbyUrl }}" class="lp-sw-chip is-empty" wire:navigate>
            <span class="lp-sw-badge">?</span>
            <span class="lp-sw-meta"><span class="lp-sw-eyebrow">No tenant selected</span><span class="lp-sw-name">Choose one in the Lobby</span></span>
        </a>
    @else
        <div class="lp-sw-chip">
            @if ($banner['logo_url'])
                <img class="lp-sw-logo" src="{{ $banner['logo_url'] }}" alt="{{ $banner['name'] }} logo">
            @else
                <span class="lp-sw-badge">{{ mb_strtoupper(mb_substr($banner['name'], 0, 1)) }}</span>
            @endif
            <span class="lp-sw-meta">
                <span class="lp-sw-eyebrow">Working on</span>
                <span class="lp-sw-name">{{ $banner['name'] }}</span>
            </span>
        </div>
        <button type="button" class="lp-sw-exit" wire:click="exitSite" title="Leave this tenant and return to the Lobby">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4.75A1.75 1.75 0 014.75 3h5.5a.75.75 0 010 1.5h-5.5a.25.25 0 00-.25.25v10.5c0 .138.112.25.25.25h5.5a.75.75 0 010 1.5h-5.5A1.75 1.75 0 013 15.25V4.75zm11.03 2.72a.75.75 0 011.06 0l2.5 2.5a.75.75 0 010 1.06l-2.5 2.5a.75.75 0 11-1.06-1.06l1.22-1.22H8.75a.75.75 0 010-1.5h6.5l-1.22-1.22a.75.75 0 010-1.06z" clip-rule="evenodd"/></svg>
            Exit site
        </button>
    @endif
</div>
