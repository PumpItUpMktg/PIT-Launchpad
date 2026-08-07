<x-filament-panels::page>
    @include('filament.operate.partials.interactions')
    <style>
        .rdy-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
        .rdy-sel { font-size:13px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 10px; background:transparent; }
        .rdy-btn { font-size:12.5px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 11px; background:transparent; cursor:pointer; white-space:nowrap; }
        .rdy-btn.primary { background:#4f46e5; color:#fff; border-color:#4f46e5; }
        .rdy-empty { border:1px dashed rgba(148,163,184,.4); border-radius:12px; padding:20px; color:#64748b; font-size:14px; }
        .rdy-row { border:1px solid rgba(148,163,184,.28); border-radius:12px; padding:12px 15px; margin-bottom:9px; display:flex; align-items:center; gap:14px; }
        .rdy-glyph { font-size:16px; font-weight:700; width:20px; text-align:center; }
        .rdy-ok .rdy-glyph { color:#16a34a; }
        .rdy-warn .rdy-glyph { color:#d97706; }
        .rdy-bad .rdy-glyph { color:#dc2626; }
        .rdy-warn { background:rgba(217,119,6,.05); }
        .rdy-bad { background:rgba(220,38,38,.05); }
        .rdy-step { font-size:15px; color:#94a3b8; width:22px; text-align:center; }
        .rdy-body { flex:1; min-width:0; }
        .rdy-label { font-weight:600; font-size:14px; }
        .rdy-detail { font-size:12.5px; color:#64748b; margin-top:2px; }
        .rdy-fix { font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; background:rgba(148,163,184,.16); color:#475569; }
    </style>

    <div class="rdy-head">
        <label style="font-size:13px;color:#64748b">Tenant</label>
        <select class="rdy-sel" wire:change="setSite($event.target.value)">
            @foreach ($this->siteOptions as $id => $label)
                <option value="{{ $id }}" @selected($id === $siteId)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="button" class="rdy-btn primary" wire:click="reconcile(false)" wire:loading.attr="disabled"
                wire:confirm="Re-align this tenant to its current silo tree and queue the affected republishes?">Reconcile</button>
        <button type="button" class="rdy-btn" wire:click="reconcile(true)" wire:loading.attr="disabled"
                wire:confirm="Rewrite the silo structure from services, re-materialize pages, then reconcile? Heavier — use only when the structure changed.">Rebuild structure &amp; reconcile</button>
    </div>

    @if ($this->rows === [])
        <div class="rdy-empty">Pick a tenant to see its build-stage readiness.</div>
    @else
        @foreach ($this->rows as $row)
            <div class="rdy-row rdy-{{ $row['status'] }}">
                <span class="rdy-step">{{ $row['step'] }}</span>
                <span class="rdy-glyph">{{ $row['glyph'] }}</span>
                <div class="rdy-body">
                    <div class="rdy-label">{{ $row['label'] }}</div>
                    <div class="rdy-detail">{{ $row['detail'] }}</div>
                </div>
                @if ($row['fix'])
                    <span class="rdy-fix">{{ $row['fix'] }}</span>
                @endif
            </div>
        @endforeach
        <p style="font-size:12px;color:#94a3b8;margin-top:12px">
            Derived from stored records only — no live WordPress check. Run <strong>Reconcile</strong> to re-align the amber/red rows and queue the affected republishes; nothing changes until you do.
        </p>
    @endif
</x-filament-panels::page>
