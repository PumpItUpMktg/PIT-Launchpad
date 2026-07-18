<x-filament-panels::page>
    <style>
        .orph-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .orph-sel { font-size:13px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 10px; background:transparent; }
        .orph-empty { border:1px dashed rgba(148,163,184,.4); border-radius:12px; padding:20px; color:#64748b; font-size:14px; }
        .orph-card { border:1px solid rgba(148,163,184,.28); border-radius:12px; padding:13px 15px; margin-bottom:10px; display:flex; align-items:flex-start; gap:14px; }
        .orph-badge { font-size:10px; text-transform:uppercase; letter-spacing:.06em; font-weight:700; padding:3px 8px; border-radius:999px; white-space:nowrap; }
        .orph-orphaned_child { background:rgba(234,88,12,.12); color:#c2410c; }
        .orph-stranded_live { background:rgba(220,38,38,.12); color:#b91c1c; }
        .orph-missing_redirect { background:rgba(37,99,235,.12); color:#1d4ed8; }
        .orph-body { flex:1; min-width:0; }
        .orph-title { font-weight:600; font-size:14px; }
        .orph-url { font-family:ui-monospace,monospace; font-size:12.5px; color:#475569; word-break:break-all; }
        .orph-detail { font-size:12.5px; color:#64748b; margin-top:3px; }
        .orph-fix { font-size:12px; color:#94a3b8; margin-top:3px; }
        .orph-btn { font-size:12.5px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 11px; background:transparent; cursor:pointer; white-space:nowrap; }
        .orph-btn.primary { background:#4f46e5; color:#fff; border-color:#4f46e5; }
        .orph-btn.danger { color:#b91c1c; border-color:rgba(185,28,28,.4); }
    </style>

    <div class="orph-head">
        <label style="font-size:13px;color:#64748b">Tenant</label>
        <select class="orph-sel" wire:change="setSite($event.target.value)">
            @foreach ($this->siteOptions as $id => $label)
                <option value="{{ $id }}" @selected($id === $siteId)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="button" class="orph-btn" wire:click="rescan" wire:loading.attr="disabled">Rescan</button>
        <span style="margin-left:auto;font-size:13px;color:#64748b">{{ count($this->findings) }} issue(s)</span>
    </div>

    @if ($this->findings === [])
        <div class="orph-empty">✓ No orphans on this tenant — every page has a live parent, nothing deleted is still on WordPress, and no retired URL is missing a 301.</div>
    @else
        @foreach ($this->findings as $f)
            <div class="orph-card">
                <span class="orph-badge orph-{{ $f['type'] }}">{{ $f['label'] }}</span>
                <div class="orph-body">
                    <div class="orph-title">{{ $f['title'] }}</div>
                    <div class="orph-url">{{ $f['url'] }}</div>
                    <div class="orph-detail">{{ $f['detail'] }}</div>
                    <div class="orph-fix">Fix: {{ $f['fix'] }}</div>
                </div>
                @if ($f['type'] === 'missing_redirect' && $f['content_id'])
                    <button type="button" class="orph-btn primary" wire:click="createRedirect('{{ $f['content_id'] }}')">Create 301</button>
                @elseif ($f['type'] === 'stranded_live' && $f['content_id'])
                    <button type="button" class="orph-btn danger" wire:click="takeDown('{{ $f['content_id'] }}')"
                            wire:confirm="Remove this page from WordPress?">Take down</button>
                @endif
            </div>
        @endforeach
    @endif
</x-filament-panels::page>
