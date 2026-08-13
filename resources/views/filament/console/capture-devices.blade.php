<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $devices = $this->devices; @endphp

    <style>
        .cd-wrap { display:flex; flex-direction:column; gap:18px; max-width:760px; }
        .cd-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:16px; display:flex; flex-direction:column; gap:12px; }
        .cd-card h3 { margin:0; font-size:14.5px; }
        .cd-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        .cd-field label { display:block; font-size:11px; color:#94a3b8; margin-bottom:4px; }
        .cd-field input { border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:9px 11px; font-size:13px; background:transparent; color:inherit; min-width:200px; }
        .cd-btn { font-size:12.5px; font-weight:600; padding:9px 16px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
        .cd-btn.go { border-color:rgba(22,163,74,.5); color:#15803d; }
        .cd-btn.danger { border-color:rgba(220,38,38,.5); color:#dc2626; }
        .cd-issued { border:1px solid rgba(22,163,74,.5); background:rgba(22,163,74,.08); border-radius:12px; padding:16px; }
        .cd-issued .code { font-size:28px; font-weight:800; letter-spacing:.18em; color:#15803d; font-variant-numeric:tabular-nums; }
        .cd-issued .link { font-size:12.5px; word-break:break-all; }
        .cd-issued .link input { width:100%; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:8px 10px; font-size:12.5px; background:#fff; color:#0f172a; }
        .cd-dev { display:flex; align-items:center; justify-content:space-between; gap:10px; border:1px solid rgba(148,163,184,.3); border-radius:9px; padding:10px 13px; }
        .cd-dev .meta { font-size:12px; color:#64748b; }
        .cd-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:99px; }
        .cd-badge.on { background:rgba(22,163,74,.15); color:#15803d; }
        .cd-badge.off { background:rgba(148,163,184,.18); color:#94a3b8; }
        .cd-empty { color:#94a3b8; font-size:13px; }
    </style>

    <div class="cd-wrap">
        @if ($lastIssued)
            <div class="cd-issued" wire:key="issued">
                <h3 style="margin:0 0 8px;">Send this to {{ $lastIssued['name'] }}</h3>
                <div class="cd-empty" style="color:#15803d;margin-bottom:6px;">They open the link on their phone → “Add to Home Screen” → enter the code. The code is one-time and expires soon.</div>
                <div class="link" style="margin-bottom:10px;"><input type="text" readonly value="{{ $lastIssued['link'] }}" onclick="this.select()"></div>
                <div class="code">{{ $lastIssued['code'] }}</div>
                <div style="margin-top:12px;"><button class="cd-btn" wire:click="dismissIssued">Done</button></div>
            </div>
        @endif

        <div class="cd-card">
            <h3>Add a tech</h3>
            <div class="cd-row">
                <div class="cd-field"><label>Name</label><input type="text" wire:model="newName" placeholder="Mike R."></div>
                <div class="cd-field"><label>Email (to send the invite)</label><input type="email" wire:model="newEmail" placeholder="mike@example.com"></div>
                <div class="cd-field"><label>Phone (optional)</label><input type="tel" wire:model="newPhone" placeholder="+1 555 123 4567"></div>
                <button class="cd-btn go" wire:click="addDevice" @disabled($this->siteId === null)>Add &amp; send invite</button>
            </div>
            @if ($this->siteId === null)
                <div class="cd-empty">Pick a site above first.</div>
            @else
                <div class="cd-empty">With an email we send the link + code straight to the tech. No email? You'll get the link + code to pass along.</div>
            @endif
        </div>

        <div class="cd-card">
            <h3>Devices</h3>
            @forelse ($devices as $d)
                <div class="cd-dev" wire:key="dev-{{ $d['id'] }}">
                    <span>
                        <strong>{{ $d['name'] }}</strong>
                        <span class="cd-badge {{ $d['active'] ? 'on' : 'off' }}">{{ $d['active'] ? 'Active' : 'Revoked' }}</span>
                        <div class="meta">{{ $d['email'] ?: 'no email' }} · {{ $d['phone'] ?: 'no phone' }}{{ $d['last_active'] ? ' · last active '.$d['last_active'] : ' · never signed in' }}</div>
                    </span>
                    @if ($d['active'])
                        <span style="display:flex;gap:8px;">
                            <button class="cd-btn" wire:click="reissueCode('{{ $d['id'] }}')">{{ $d['email'] ? 'Resend invite' : 'New code' }}</button>
                            <button class="cd-btn danger" wire:click="revoke('{{ $d['id'] }}')" wire:confirm="Revoke {{ $d['name'] }}'s device? Their app stops working immediately.">Revoke</button>
                        </span>
                    @endif
                </div>
            @empty
                <div class="cd-empty">No capture devices on this site yet. Add a tech above.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
