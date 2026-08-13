<x-filament-panels::page>
    @php $site = $this->site; $checklist = $this->checklist; $connected = $this->connected; @endphp

    <style>
        .jc-wrap { display:flex; flex-direction:column; gap:18px; max-width:820px; }
        .jc-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:16px 18px; display:flex; flex-direction:column; gap:12px; }
        .jc-card.done { border-color:rgba(22,163,74,.5); background:rgba(22,163,74,.05); }
        .jc-step { font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#94a3b8; }
        .jc-card h3 { margin:0; font-size:15px; }
        .jc-sub { font-size:12.5px; color:#94a3b8; margin:0; }
        .jc-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        .jc-field { display:flex; flex-direction:column; gap:4px; }
        .jc-field label { font-size:11px; color:#94a3b8; font-weight:600; }
        .jc-field input { border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:9px 11px; font-size:13px; background:transparent; color:inherit; min-width:190px; }
        .jc-btn { font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; cursor:pointer; border:1px solid #4f46e5; background:#4f46e5; color:#fff; }
        .jc-btn.ghost { background:transparent; color:inherit; border-color:rgba(148,163,184,.45); }
        .jc-btn:disabled { opacity:.5; cursor:not-allowed; }
        .jc-check { display:flex; flex-direction:column; gap:5px; margin-top:4px; }
        .jc-check div { font-size:12.5px; }
        .jc-check .ok { color:#15803d; }
        .jc-check .no { color:#94a3b8; }
        .jc-head { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .jc-badge { font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; background:rgba(79,70,229,.14); color:#4f46e5; }
        .jc-issued { border:1px solid rgba(22,163,74,.5); background:rgba(22,163,74,.08); border-radius:10px; padding:14px; }
        .jc-issued .code { font-size:26px; font-weight:800; letter-spacing:.16em; color:#15803d; }
        .jc-issued input { width:100%; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:8px 10px; font-size:12.5px; background:#fff; color:#0f172a; }
    </style>

    <div class="jc-wrap">
        @if ($site === null)
            <div class="jc-card">
                <span class="jc-step">Step 1 of 4</span>
                <h3>New Job Capture client</h3>
                <p class="jc-sub">Stand up a standalone Job Capture client. They’ll get the same WordPress connection a full Launchpad tenant uses, so upgrading later is just turning on features.</p>
                <div class="jc-row">
                    <div class="jc-field"><label>Business name</label><input type="text" wire:model="newBrandName" placeholder="Ace Plumbing"></div>
                    <div class="jc-field"><label>Account name (optional)</label><input type="text" wire:model="newAccountName" placeholder="defaults to business name"></div>
                    <div class="jc-field"><label>Website URL (optional)</label><input type="url" wire:model="newDomain" placeholder="https://aceplumbing.com"></div>
                    <button class="jc-btn" wire:click="createClient">Create client</button>
                </div>
            </div>
        @else
            <div class="jc-card">
                <div class="jc-head">
                    <div>
                        <span class="jc-step">Onboarding</span>
                        <h3>{{ $site->brand_name }}</h3>
                    </div>
                    <span class="jc-badge">{{ $site->status->value === 'active' ? 'Active' : 'Onboarding' }}</span>
                </div>
                <button class="jc-btn ghost" style="align-self:flex-start;" wire:click="activate" @disabled(! $connected)>Activate — jobs can publish</button>
                @unless ($connected)<p class="jc-sub">Connect WordPress below to enable activation.</p>@endunless
            </div>

            <div class="jc-card {{ $connected ? 'done' : '' }}">
                <span class="jc-step">Step 2 of 4</span>
                <h3>Connect WordPress</h3>
                <p class="jc-sub">The same verified connection Launchpad uses — jobs publish through it.</p>
                @unless ($connected)
                    <div class="jc-row">
                        <div class="jc-field"><label>Site URL</label><input type="url" wire:model="baseUrl" placeholder="https://aceplumbing.com"></div>
                        <div class="jc-field"><label>WP username</label><input type="text" wire:model="username" placeholder="admin"></div>
                        <div class="jc-field"><label>Application password</label><input type="password" wire:model="appPassword"></div>
                        <button class="jc-btn" wire:click="connectWordpress">Connect &amp; verify</button>
                    </div>
                @endunless
                @if (count($checklist) > 0)
                    <div class="jc-check">
                        @foreach ($checklist as $label => $ok)
                            <div class="{{ $ok ? 'ok' : 'no' }}">{{ $ok ? '✓' : '○' }} {{ $label }}</div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="jc-card">
                <span class="jc-step">Step 3 of 4</span>
                <h3>Add field techs</h3>
                <p class="jc-sub">Each tech gets a capture-app login. With an email we send the invite for you; otherwise you’ll get the link + code to pass along.</p>
                <div class="jc-row">
                    <div class="jc-field"><label>Name</label><input type="text" wire:model="techName" placeholder="Mike R."></div>
                    <div class="jc-field"><label>Email (to send the invite)</label><input type="email" wire:model="techEmail" placeholder="mike@example.com"></div>
                    <div class="jc-field"><label>Phone (optional)</label><input type="tel" wire:model="techPhone"></div>
                    <button class="jc-btn" wire:click="addTech">Add &amp; invite</button>
                </div>
                @if ($this->lastIssued)
                    <div class="jc-issued">
                        <p class="jc-sub" style="color:#15803d;margin-bottom:8px;">Capture link + one-time code for {{ $this->lastIssued['name'] }} — they open it on their phone and enter the code.</p>
                        <input type="text" readonly value="{{ $this->lastIssued['link'] }}" onclick="this.select()">
                        <div class="code" style="margin-top:8px;">{{ $this->lastIssued['code'] }}</div>
                        <button class="jc-btn ghost" style="margin-top:10px;" wire:click="dismissIssued">Done</button>
                    </div>
                @endif
            </div>

            <div class="jc-card">
                <span class="jc-step">Step 4 of 4</span>
                <h3>Activate</h3>
                <p class="jc-sub">When WordPress is connected, activate the client so their captured jobs publish. You can add more techs any time from Capture Devices.</p>
                <button class="jc-btn" style="align-self:flex-start;" wire:click="activate" @disabled(! $connected)>Activate {{ $site->brand_name }}</button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
