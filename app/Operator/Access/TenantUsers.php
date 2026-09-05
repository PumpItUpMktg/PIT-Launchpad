<?php

namespace App\Operator\Access;

use App\Filament\Pages\UsersBoard;
use App\Models\Membership;
use App\Models\Scopes\VisibleSiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;

/**
 * The read-model behind the operator **Users** workspace — **who can access the LOCKED tenant, and at
 * what role**. It is memberships-for-this-site, never a global user list: a tenant-locked surface that
 * enumerated every user would leak other tenants' membership (the exact shape the tenant-lock
 * remediation removed). So it lists only {@see Membership} rows that grant this site — the site-level
 * grants plus the account-wide grants on this tenant's account — with the granted user's identity.
 *
 * UI-agnostic and testable; the Filament page ({@see UsersBoard}) is thin over it and every write it
 * drives targets the locked {@see ActiveTenant}, never a passed site id.
 */
class TenantUsers
{
    /**
     * @return array{
     *     users: list<array{membership_id: string, user_id: string, name: string, email: string, role: string, role_label: string, scope: string, revocable: bool, role_editable: bool}>,
     *     summary: array{total: int, site: int, account: int}
     * }|null
     */
    public function for(?string $siteId): ?array
    {
        if ($siteId === null) {
            return null;
        }

        $site = Site::query()->withoutGlobalScope(VisibleSiteScope::class)->find($siteId);
        if ($site === null) {
            return null;
        }
        $accountId = $site->account_id;

        // Everyone who can reach THIS site: a site-level grant on it, or an account-wide grant (null
        // site_id) on its account. No other tenant's memberships are ever queried.
        $memberships = Membership::query()
            ->with('user')
            ->where(function ($q) use ($siteId, $accountId): void {
                $q->where('site_id', $siteId)
                    ->orWhere(fn ($qq) => $qq->whereNull('site_id')->where('account_id', $accountId));
            })
            ->get();

        // Per-user membership count: a global-role edit from this per-tenant surface is only safe for a
        // user whose ONLY membership is this site (a single-tenant user), so it can't affect other tenants.
        $counts = Membership::query()
            ->whereIn('user_id', $memberships->pluck('user_id')->unique()->all())
            ->selectRaw('user_id, count(*) as c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        $rows = $memberships
            ->filter(fn (Membership $m): bool => $m->user !== null)
            ->map(function (Membership $m) use ($counts): array {
                $scope = $m->site_id === null ? 'account' : 'site';

                return [
                    'membership_id' => (string) $m->id,
                    'user_id' => (string) $m->user->id,
                    'name' => (string) $m->user->name,
                    'email' => (string) $m->user->email,
                    'role' => $m->user->role->value,
                    'role_label' => $m->user->role->label(),
                    'scope' => $scope,
                    'revocable' => $scope === 'site',
                    'role_editable' => $scope === 'site' && (int) ($counts[$m->user_id] ?? 0) === 1,
                ];
            })
            ->values()
            ->all();

        // Site-level grants first (the tenant's own people), then account-wide, each name-ordered.
        usort($rows, fn (array $a, array $b): int => [$a['scope'] === 'account', mb_strtolower($a['name'])]
            <=> [$b['scope'] === 'account', mb_strtolower($b['name'])]);

        return [
            'users' => $rows,
            'summary' => [
                'total' => count($rows),
                'site' => count(array_filter($rows, fn (array $r): bool => $r['scope'] === 'site')),
                'account' => count(array_filter($rows, fn (array $r): bool => $r['scope'] === 'account')),
            ],
        ];
    }
}
