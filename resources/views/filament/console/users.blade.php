<x-filament-panels::page>
    @php $users = $this->users; $roleOptions = $this->roleOptions; $siteOptions = $this->siteOptions; @endphp

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
                @if ($newRole === 'site_admin')
                    <div class="us-field"><label>Site</label>
                        <select class="us-input" wire:model="newSiteId">
                            <option value="">—</option>
                            @foreach ($siteOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button class="us-btn" wire:click="createUser">Create user</button>
            </div>
        </div>

        <div class="us-card">
            <h3>Users ({{ count($users) }})</h3>
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
