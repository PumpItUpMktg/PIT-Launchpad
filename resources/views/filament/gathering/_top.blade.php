{{-- Shared New-Setup chrome: minimal g- styles (functionality over polish — the visual pass is a
     later relay), the readiness chip (state, never a wall), and the working-site switcher. --}}
<style>
    .g-wrap { display:flex; flex-direction:column; gap:14px; }
    .g-head { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; }
    .g-sub { color:#64748b; font-size:13px; max-width:64ch; margin:4px 0 0; }
    .g-chip { display:inline-flex; align-items:center; gap:7px; font-size:12px; font-weight:600; padding:4px 12px; border-radius:99px; }
    .g-chip .dot { width:8px; height:8px; border-radius:50%; }
    .g-chip.complete { background:rgba(22,163,74,.12); color:#16a34a; } .g-chip.complete .dot { background:#16a34a; }
    .g-chip.attention { background:rgba(217,119,6,.13); color:#b45309; } .g-chip.attention .dot { background:#d97706; }
    .g-chip.empty { background:rgba(148,163,184,.15); color:#64748b; } .g-chip.empty .dot { background:#94a3b8; }
    .g-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:4px 8px; background:transparent; }
    .g-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; padding:16px 18px; display:flex; flex-direction:column; gap:12px; }
    .g-card h3 { margin:0; font-size:14.5px; }
    .g-hint { font-size:12px; color:#94a3b8; margin:-6px 0 0; }
    .g-field label { display:block; font-size:12px; font-weight:600; margin-bottom:4px; }
    .g-input, .g-textarea { width:100%; font-size:13px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:7px 10px; background:transparent; font-family:inherit; }
    .g-grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
    .g-btn { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:600; padding:6px 14px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; cursor:pointer; width:fit-content; }
    .g-btn.primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .g-btn.danger { color:#dc2626; }
    .g-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .g-seed { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:1px 7px; border-radius:5px; background:rgba(79,70,229,.12); color:#6366f1; }
    .g-seed.confirmed { background:rgba(22,163,74,.12); color:#16a34a; }
    .g-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:13px 15px; color:#94a3b8; font-size:13px; }
    .g-list { display:flex; flex-direction:column; }
    .g-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid rgba(148,163,184,.16); font-size:13px; }
    .g-item:last-child { border-bottom:0; }
    .g-muted { color:#94a3b8; font-size:12px; }
</style>

@php $r = $this->readiness(); @endphp
<div class="g-head">
    <div>
        <span class="g-chip {{ $r['state'] }}"><span class="dot"></span>{{ $r['label'] }}</span>
        <p class="g-sub">{{ $subtitle }}</p>
    </div>
    <select class="g-select" wire:change="setSite($event.target.value)">
        @foreach ($this->siteOptions as $id => $label)
            <option value="{{ $id }}" @selected($id === $this->siteId)>{{ $label }}</option>
        @endforeach
    </select>
</div>
