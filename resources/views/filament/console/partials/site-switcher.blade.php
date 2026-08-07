{{-- Shared console site switcher: picks the active tenant for this page, scoped to what the user may
     see (ConsoleContext). A single-site Site Admin sees a static chip; a Super Admin gets the dropdown. --}}
@php $siteOptions = $this->siteOptions; @endphp
<style>
    .cs-bar { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
    .cs-label { font-size:12px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
    .cs-select { font-size:13.5px; padding:6px 11px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
    .cs-chip { font-size:13.5px; font-weight:650; padding:6px 12px; border-radius:8px; background:rgba(99,102,241,.1); color:#4f46e5; }
    .cs-none { font-size:13px; color:#94a3b8; }
</style>
<div class="cs-bar">
    <span class="cs-label">Site</span>
    @if (count($siteOptions) === 0)
        <span class="cs-none">No sites assigned to you yet.</span>
    @elseif (count($siteOptions) === 1)
        <span class="cs-chip">{{ reset($siteOptions) }}</span>
    @else
        <select class="cs-select" wire:model.live="siteId">
            @foreach ($siteOptions as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    @endif
</div>
