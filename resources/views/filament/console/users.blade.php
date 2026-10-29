<x-filament-panels::page>
    @php $users = $this->users; $techs = $this->techs; $roleOptions = $this->roleOptions; $siteOptions = $this->siteOptions; $techCount = array_sum(array_map(fn (array $g): int => count($g['techs']), $techs)); @endphp

    <style>
        .us-wrap { display:flex; flex-direction:column; gap:18px; }
        .us-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:15px 16px; }
        .us-card h3 { margin:0 0 12px; font-size:14.5px; }
        .us-form { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:10px; align-items:end; }
        .us-field { display:flex; flex-direction:column; gap:4px; }
        .us-field label { font-size:11px; color:#94a3b8; font-weight:600; }
        .us-input { font-size:13.5px; padding:7px 10px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
        .us-btn { font-size:13px; font-weight:600; padding:8px 15px; border-radius:8px; cursor:pointer; border:1px solid #4f46e5; background:#4f46e5; color:#fff; }
        .us-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; border-top:1px solid rgba(148,163,184,.2); padding:10px 0; }
        .us-row:first-of-type { border-top:0; }
        .us-id { flex:1 1 240px; min-width:0; }
        .us-name { font-size:14px; font-weight:600; margin:0; }
        .us-email { font-size:12px; color:#94a3b8; }
        .us-sites { font-size:11.5px; color:#64748b; }
        .us-role select { font-size:12.5px; padding:5px 9px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
        .us-group { margin-top:10px; }
        .us-group h4 { margin:0 0 2px; font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
        .us-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:99px; margin-left:6px; vertical-align:middle; }
        .us-badge.st-active { background:rgba(22,163,74,.15); color:#15803d; }
        .us-badge.st-invited { background:rgba(79,70,229,.12); color:#4f46e5; }
        .us-badge.st-inactive { background:rgba(148,163,184,.18); color:#94a3b8; }
        .us-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .us-sm { font-size:12.5px; font-weight:600; padding:6px 11px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
        .us-sm.danger { border-color:rgba(220,38,38,.5); color:#dc2626; }
        .us-sm.go { border-color:#4f46e5; background:#4f46e5; color:#fff; }
    </style>

    <div class="us-wrap">
        <div class="us-card">
            <h3>Add a user</h3>
            <div class="us-form">
                <div class="us-field"><label>Name</label><input class="us-input" type="text" wire:model="newName"></div>
                <div class="us-field"><label>Email</label><input class="us-input" type="email" wire:model="newEmail"></div>
                <div class="us-field"><label>Role</label>
                    <select class="us-input" wire:model.live="newRole">
                        @foreach ($roleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($newRole === 'site_admin' || $newRole === 'tech')
                    <div class="us-field"><label>Site</label>
                        <select class="us-input" wire:model="newSiteId">
                            <option value="">—</option>
                            @foreach ($siteOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="us-btn" wire:click="createUser">{{ $newRole === 'tech' ? 'Add tech'.(isset($siteOptions[$newSiteId ?? '']) ? ' to '.$siteOptions[$newSiteId] : '') : 'Create user' }}</button>
            </div>
            @if ($newRole === 'tech')
                <p class="us-email" style="margin-top:8px;">The tech's phone is bound to the client picked above and only ever offers that client's services. They sign in with a device code — with an email we send it to them; without one you hand over the link + code shown here.</p>
            @endif

            @if ($this->lastIssued)
                <div class="us-card" style="margin-top:12px; border-color:#4f46e5;">
                    <h3>Capture link for {{ $this->lastIssued['name'] }} — device on {{ $this->lastIssued['site'] }}</h3>
                    <div class="us-field"><label>Link</label><input class="us-input" type="text" readonly value="{{ $this->lastIssued['link'] }}"></div>
                    <div class="us-field" style="margin-top:8px;"><label>Login code</label><input class="us-input" type="text" readonly value="{{ $this->lastIssued['code'] }}"></div>
                    <button class="us-btn" style="margin-top:10px; background:transparent; color:inherit; border-color:rgba(148,163,184,.4);" wire:click="dismissIssued">Dismiss</button>
                </div>
            @endif
        </div>

        <div class="us-card">
            <h3>Technicians ({{ $techCount }})</h3>
            <p class="us-email" style="margin:-6px 0 8px;">One row per capture device, under the client it is bound to. Active = signed in and working; Invited = code issued, never signed in; Inactive = revoked. Remove is only offered once a tech is inactive. Moving a tech rebinds their phone to another client on its next sync — jobs already captured stay with the client they were done for.</p>
            @forelse ($techs as $group)
                <div class="us-group" wire:key="tech-group-{{ $group['site_id'] }}">
                    <h4>{{ $group['site'] }}</h4>
                    @foreach ($group['techs'] as $t)
                        <div class="us-row" wire:key="tech-{{ $t['id'] }}">
                            <div class="us-id">
                                <p class="us-name">{{ $t['name'] }} <span class="us-badge st-{{ $t['status'] }}">{{ $t['status_label'] }}</span></p>
                                <div class="us-email">{{ implode(' · ', array_filter([$t['phone'], $t['email'], $t['last_active'] ? 'last active '.$t['last_active'] : ($t['status'] === 'invited' ? 'never signed in' : null)])) }}</div>
                            </div>
                            <div class="us-actions">
                                @if (count($siteOptions) > 1)
                                    <select class="us-input" style="font-size:12.5px; padding:5px 9px;" wire:change="moveTech('{{ $t['id'] }}', $event.target.value)" wire:confirm="Move {{ $t['name'] }} to the selected client? Their phone will offer that client's services on its next sync.">
                                        @foreach ($siteOptions as $id => $name)
                                            <option value="{{ $id }}" @selected($t['site_id'] === $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if ($t['status'] === 'inactive')
                                    <button class="us-sm go" wire:click="reactivateTech('{{ $t['id'] }}')">Reactivate</button>
                                    <button class="us-sm danger" wire:click="removeTech('{{ $t['id'] }}')" wire:confirm="Remove {{ $t['name'] }} for good? Their device and account are deleted; captured jobs are kept.">Remove</button>
                                @else
                                    <button class="us-sm" wire:click="refreshTech('{{ $t['id'] }}')">{{ $t['email'] !== '' ? 'Resend invite' : 'New code' }}</button>
                                    <button class="us-sm danger" wire:click="revokeTech('{{ $t['id'] }}')" wire:confirm="Revoke {{ $t['name'] }}'s device? Their app stops working immediately.">Revoke</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="us-email">No technicians yet — add one above with the role "Tech (capture device)".</div>
            @endforelse
        </div>

        <div class="us-card">
            <h3>Team ({{ count($users) }})</h3>
            @foreach ($users as $u)
                <div class="us-row" wire:key="user-{{ $u['id'] }}">
                    <div class="us-id">
                        <p class="us-name">{{ $u['name'] }} <span class="us-email">· {{ $u['email'] }}</span></p>
                        @if (count($u['sites']) > 0)
                            <div class="us-sites">Sites: {{ implode(', ', $u['sites']) }}</div>
                        @endif
                    </div>
                    <div class="us-role">
                        <select wire:change="setRole('{{ $u['id'] }}', $event.target.value)">
                            @foreach ($roleOptions as $value => $label)
                                <option value="{{ $value }}" @selected($u['role'] === $value)>{{ $label }}</option>
                            @endforeach
                            @if (! array_key_exists($u['role'], $roleOptions))
                                <option value="{{ $u['role'] }}" selected>{{ $u['tier'] }}</option>
                            @endif
                        </select>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
