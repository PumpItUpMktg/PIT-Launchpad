<x-lp.shell
    variant="table"
    eyebrow="System"
    title="Users"
    lede="Who can access this tenant, and at what role. Grant a client or site-admin access to this site; revoke it here too. Platform staff and multi-tenant users are managed in the Console.">

    @php($board = $this->board)

    <style>
        .us-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .us-stat { background:var(--card); border:1px solid var(--line); border-radius:11px; padding:12px 16px; min-width:120px; }
        .us-stat .n { font-family:'Spline Sans Mono',monospace; font-size:22px; font-weight:600; color:var(--teal-deep); }
        .us-stat .l { font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
        .us-table { width:100%; border-collapse:collapse; font-size:13px; background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .us-table th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; padding:11px 14px; border-bottom:1px solid var(--line); background:var(--paper); }
        .us-table td { padding:12px 14px; border-bottom:1px solid var(--line); vertical-align:middle; }
        .us-table tr:last-child td { border-bottom:0; }
        .us-name { font-weight:700; color:var(--ink); }
        .us-email { color:var(--ink-soft); font-size:12px; }
        .us-actions { display:flex; gap:8px; justify-content:flex-end; align-items:center; }
        .us-btn { font-size:12px; font-weight:600; background:none; border:1px solid var(--line); border-radius:8px; padding:5px 11px; cursor:pointer; color:var(--ink); }
        .us-btn:hover { border-color:var(--teal-deep); }
        .us-btn.danger { color:#B5341A; } .us-btn.danger:hover { border-color:#B5341A; }
        .us-dash { color:var(--ungrouped); }
    </style>

    @if ($board === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to manage who can access it.
        </x-lp.empty>
    @else
        <div class="us-stats">
            <div class="us-stat"><div class="n">{{ number_format($board['summary']['total']) }}</div><div class="l">With access</div></div>
            <div class="us-stat"><div class="n">{{ number_format($board['summary']['site']) }}</div><div class="l">This site</div></div>
            <div class="us-stat"><div class="n">{{ number_format($board['summary']['account']) }}</div><div class="l">Account-wide</div></div>
        </div>

        @if (empty($board['users']))
            <x-lp.empty title="No one has access yet" action="Grant access">
                Use “Grant access” above to add a client or site-admin to this tenant.
            </x-lp.empty>
        @else
            <table class="us-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Access</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($board['users'] as $u)
                        <tr wire:key="us-{{ $u['membership_id'] }}">
                            <td>
                                <div class="us-name">{{ $u['name'] !== '' ? $u['name'] : '—' }}</div>
                                <div class="us-email">{{ $u['email'] }}</div>
                            </td>
                            <td><x-lp.chip tone="info">{{ $u['role_label'] }}</x-lp.chip></td>
                            <td>
                                @if ($u['scope'] === 'site')
                                    <x-lp.chip tone="neutral">This site</x-lp.chip>
                                @else
                                    <x-lp.chip tone="warn">Account-wide</x-lp.chip>
                                @endif
                            </td>
                            <td>
                                <div class="us-actions">
                                    @if ($u['role_editable'])
                                        @php($toOperatorSafe = $u['role'] === \App\Enums\UserRole::SiteAdmin->value)
                                        <button type="button" class="us-btn"
                                            wire:click="setRole('{{ $u['user_id'] }}', '{{ $toOperatorSafe ? \App\Enums\UserRole::Client->value : \App\Enums\UserRole::SiteAdmin->value }}')">
                                            Make {{ $toOperatorSafe ? 'Client' : 'Site Admin' }}
                                        </button>
                                    @endif
                                    @if ($u['revocable'])
                                        <button type="button" class="us-btn danger"
                                            wire:click="revoke('{{ $u['user_id'] }}')"
                                            wire:confirm="Revoke this user's access to this tenant?">Revoke</button>
                                    @else
                                        <span class="us-dash" title="Managed at account level">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
</x-lp.shell>
