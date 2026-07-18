<x-filament-panels::page>
    <style>
        .hm-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
        .hm-sel { font-size:13px; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:5px 10px; background:transparent; }
        .hm-note { font-size:12.5px; color:#94a3b8; margin-bottom:18px; }
        .hm-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px; }
        .hm-col h3 { font-size:13px; font-weight:700; margin-bottom:2px; }
        .hm-col .sub { font-size:12px; color:#94a3b8; margin-bottom:9px; }
        .hm-row { display:flex; align-items:center; gap:10px; border:1px solid rgba(148,163,184,.28); border-radius:10px; padding:8px 11px; margin-bottom:8px; }
        .hm-row .lbl { flex:1; min-width:0; }
        .hm-row .t { font-weight:600; font-size:13.5px; }
        .hm-row .u { font-family:ui-monospace,monospace; font-size:11.5px; color:#64748b; }
        .hm-arrows { display:flex; flex-direction:column; gap:2px; }
        .hm-btn { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:2px 8px; background:transparent; cursor:pointer; line-height:1.2; }
        .hm-btn:disabled { opacity:.35; cursor:not-allowed; }
        .hm-btn.danger { color:#b91c1c; border-color:rgba(185,28,28,.35); }
        .hm-btn.primary { color:#4338ca; border-color:rgba(67,56,202,.4); }
        .hm-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:12px; color:#94a3b8; font-size:12.5px; }
    </style>

    <div class="hm-head">
        <label style="font-size:13px;color:#64748b">Tenant</label>
        <select class="hm-sel" wire:change="setSite($event.target.value)">
            @foreach ($this->siteOptions as $id => $label)
                <option value="{{ $id }}" @selected($id === $siteId)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="hm-note">Move items with the arrows. Changes go live on the next <b>Sync header &amp; footer</b> push (Portfolio → ⋯).</div>

    <div class="hm-grid">
        {{-- MAIN MENU --}}
        <div class="hm-col">
            <h3>Main menu</h3>
            <div class="sub">The primary header row — company pages + Areas We Serve.</div>
            @php $main = $this->menus['main']; @endphp
            @forelse ($main as $i => $item)
                <div class="hm-row" wire:key="main-{{ $item['id'] }}">
                    <div class="hm-arrows">
                        <button class="hm-btn" wire:click="moveMainUp('{{ $item['id'] }}')" @disabled($i === 0)>↑</button>
                        <button class="hm-btn" wire:click="moveMainDown('{{ $item['id'] }}')" @disabled($i === count($main) - 1)>↓</button>
                    </div>
                    <div class="lbl"><div class="t">{{ $item['title'] }}</div><div class="u">{{ $item['slug'] }}</div></div>
                </div>
            @empty
                <div class="hm-empty">No main-menu pages yet (About / Contact / FAQ / Areas We Serve).</div>
            @endforelse
        </div>

        {{-- SERVICES BAR --}}
        <div class="hm-col">
            <h3>Services bar</h3>
            <div class="sub">The slim "Services" row beneath the main menu.</div>
            @php $services = $this->menus['services']; @endphp
            @forelse ($services as $i => $item)
                <div class="hm-row" wire:key="svc-{{ $item['id'] }}">
                    <div class="hm-arrows">
                        <button class="hm-btn" wire:click="moveServiceUp('{{ $item['id'] }}')" @disabled($i === 0)>↑</button>
                        <button class="hm-btn" wire:click="moveServiceDown('{{ $item['id'] }}')" @disabled($i === count($services) - 1)>↓</button>
                    </div>
                    <div class="lbl"><div class="t">{{ $item['title'] }}</div><div class="u">{{ $item['slug'] }}</div></div>
                    <button class="hm-btn danger" wire:click="removeService('{{ $item['id'] }}')">Remove</button>
                </div>
            @empty
                <div class="hm-empty">No services pinned — the header auto-shows the top 8 by importance. Add services below to curate the order.</div>
            @endforelse

            @if ($this->menus['available'] !== [])
                <div class="sub" style="margin-top:14px">Add a service</div>
                @foreach ($this->menus['available'] as $item)
                    <div class="hm-row" wire:key="avail-{{ $item['id'] }}">
                        <div class="lbl"><div class="t">{{ $item['title'] }}</div><div class="u">{{ $item['slug'] }}</div></div>
                        <button class="hm-btn primary" wire:click="addService('{{ $item['id'] }}')">Add</button>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-filament-panels::page>
