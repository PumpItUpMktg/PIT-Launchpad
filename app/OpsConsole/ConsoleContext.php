<?php

namespace App\OpsConsole;

use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Illuminate\Support\Collection;

/**
 * The Operations Console's "which site am I working on" — a session-backed selection independent of the
 * operator cockpit's {@see ActiveTenant} (its own key, so choosing a site here never moves
 * the /admin panel, and vice versa). Mirrors the client portal's `ClientContext` pattern.
 *
 * Scope is enforced through the user's own visibility ({@see User::permittedSiteIds()} /
 * {@see User::canSeeSite()}): a Site Admin sees only their membership site(s); a Super Admin sees the
 * portfolio. A selection is validated against that set on every read, so a stale or forged
 * `console_site_id` can never surface another tenant's data.
 */
class ConsoleContext
{
    public const SESSION_KEY = 'console_site_id';

    /**
     * The sites the given user may operate on, name-ordered. A `null` permitted set (an unrestricted
     * Super Admin) means the whole portfolio.
     *
     * @return Collection<int, Site>
     */
    public function sites(User $user): Collection
    {
        $permitted = $user->permittedSiteIds();

        $query = Site::query()->orderBy('brand_name')->orderBy('id');
        if ($permitted !== null) {
            $query->whereIn('id', $permitted);
        }

        return $query->get();
    }

    /**
     * The console's active Site for this user: the session selection when it is still valid + visible,
     * otherwise the first site the user may see. `$selected` (a just-picked id) takes precedence and is
     * persisted when valid. Returns null only when the user can see no site at all.
     */
    public function current(User $user, ?string $selected = null): ?Site
    {
        if ($selected !== null && $user->canSeeSite($selected)) {
            $this->select($user, $selected);

            return Site::query()->find($selected);
        }

        $sessionId = session(self::SESSION_KEY);
        if (is_string($sessionId) && $sessionId !== '' && $user->canSeeSite($sessionId)) {
            return Site::query()->find($sessionId);
        }

        return $this->sites($user)->first();
    }

    /** Persist a site selection (only when the user may see it). */
    public function select(User $user, string $siteId): bool
    {
        if (! $user->canSeeSite($siteId)) {
            return false;
        }

        session([self::SESSION_KEY => $siteId]);

        return true;
    }

    /**
     * Options for a site switcher: id => display name.
     *
     * @return array<string, string>
     */
    public function options(User $user): array
    {
        return $this->sites($user)
            ->mapWithKeys(fn (Site $site): array => [
                (string) $site->id => trim((string) $site->brand_name) !== '' ? (string) $site->brand_name : 'Untitled tenant',
            ])
            ->all();
    }
}
