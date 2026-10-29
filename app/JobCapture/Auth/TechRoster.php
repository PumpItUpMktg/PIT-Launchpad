<?php

namespace App\JobCapture\Auth;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TechDevice;
use App\Models\User;
use App\OpsConsole\ConsoleContext;
use App\Security\Audit;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;

/**
 * The technician roster behind Users & access (§5): one row per capture device, grouped by the client it is
 * bound to, with the whole tech lifecycle in one place — invite (refresh the code), revoke, reactivate,
 * remove, and move to another client. The page is a thin shell over this.
 *
 * Status is derived, never stored: ACTIVE (signed in, usable), INVITED (issued, never signed in),
 * INACTIVE (revoked). Remove is a real delete and is therefore only offered for an inactive device; the
 * tech's platform account goes with it when no other device is left. Jobs keep their `tech_id` snapshot
 * (a soft reference) — nothing about captured work changes. Every write runs with the tenant lock lifted,
 * because the operator manages devices across clients from one page and the BelongsToSite write guard
 * would otherwise refuse a row for a client other than the one the cockpit is locked to.
 */
final class TechRoster
{
    public const ACTIVE = 'active';

    public const INVITED = 'invited';

    public const INACTIVE = 'inactive';

    public function __construct(
        private readonly DeviceAuthenticator $authenticator,
        private readonly TechInviteNotifier $notifier,
        private readonly Audit $audit,
    ) {}

    public static function status(TechDevice $device): string
    {
        if ($device->revoked_at !== null) {
            return self::INACTIVE;
        }

        return $device->token_hash !== null ? self::ACTIVE : self::INVITED;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::ACTIVE => 'Active',
            self::INVITED => 'Invited',
            default => 'Inactive',
        };
    }

    /**
     * The roster the given user may see, grouped by client (name-ordered), clients with no device omitted.
     *
     * @return list<array{site_id: string, site: string, techs: list<array{id: string, name: string, phone: string, email: string, status: string, status_label: string, last_active: ?string, site_id: string}>}>
     */
    public function forUser(User $user): array
    {
        $sites = app(ConsoleContext::class)->sites($user);
        $devices = TechDevice::withoutGlobalScope(SiteScope::class)
            ->whereIn('site_id', $sites->pluck('id')->map('strval')->all())
            ->orderBy('name')
            ->get()
            ->groupBy('site_id');

        $groups = [];
        foreach ($sites as $site) {
            $rows = $devices->get((string) $site->id);
            if ($rows === null || $rows->isEmpty()) {
                continue;
            }
            $groups[] = [
                'site_id' => (string) $site->id,
                'site' => trim((string) $site->brand_name) !== '' ? (string) $site->brand_name : 'Untitled tenant',
                'techs' => $rows->map(function (TechDevice $d): array {
                    $status = self::status($d);

                    return [
                        'id' => (string) $d->id,
                        'name' => (string) $d->name,
                        'phone' => (string) $d->phone,
                        'email' => (string) $d->email,
                        'status' => $status,
                        'status_label' => self::statusLabel($status),
                        'last_active' => $d->last_active_at instanceof Carbon ? $d->last_active_at->diffForHumans() : null,
                        'site_id' => (string) $d->site_id,
                    ];
                })->values()->all(),
            ];
        }

        return $groups;
    }

    /** The phone stops working at once. */
    public function revoke(TechDevice $device): void
    {
        $this->unlocked(fn () => $device->forceFill(['revoked_at' => now()])->save());
    }

    /**
     * A fresh one-time code for a live (active / invited) device, emailed when an address is on file.
     * Null for an inactive device — reactivate it instead.
     *
     * @return array{code: string, link: string, delivered: bool}|null
     */
    public function refresh(TechDevice $device): ?array
    {
        if (self::status($device) === self::INACTIVE) {
            return null;
        }

        return $this->issue($device);
    }

    /**
     * Bring a revoked device back: the revoke is cleared, the old token is dropped (the tech signs in
     * again), and a fresh code goes out.
     *
     * @return array{code: string, link: string, delivered: bool}
     */
    public function reactivate(TechDevice $device): array
    {
        $this->unlocked(fn () => $device->forceFill(['revoked_at' => null, 'token_hash' => null])->save());

        return $this->issue($device);
    }

    /**
     * Delete an INACTIVE device and, when it was the tech's last device, the tech's platform account.
     * Refused (false) for a live device — revoke first, so a working phone is never deleted by accident.
     */
    public function remove(TechDevice $device, ?User $actor = null): bool
    {
        if (self::status($device) !== self::INACTIVE) {
            return false;
        }

        $user = $device->user_id !== null ? User::query()->find($device->user_id) : null;
        $this->audit->log(AuditAction::TechRemoved, $device, $actor?->id, [
            'site_id' => (string) $device->site_id, 'name' => (string) $device->name, 'user_id' => $device->user_id,
        ]);
        $this->unlocked(fn () => $device->delete());

        if ($user !== null && $user->role === UserRole::Tech
            && ! TechDevice::withoutGlobalScope(SiteScope::class)->where('user_id', $user->id)->exists()) {
            $user->delete();
        }

        return true;
    }

    /**
     * Rebind the device to another client. The phone offers that client's services and jobs on its next
     * sync; captured jobs stay with the client they were done for. Refused (false) unless the actor may see
     * both clients, so a Site Admin can never push a tech into a tenant they do not manage.
     */
    public function move(TechDevice $device, Site $to, User $actor): bool
    {
        $from = (string) $device->site_id;
        if ($from === (string) $to->id) {
            return false;
        }
        if (! $actor->canSeeSite($from) || ! $actor->canSeeSite($to)) {
            return false;
        }

        $this->unlocked(fn () => $device->forceFill(['site_id' => (string) $to->id])->save());
        $this->audit->log(AuditAction::TechMoved, $device, $actor->id, [
            'from_site_id' => $from, 'to_site_id' => (string) $to->id, 'name' => (string) $device->name,
        ]);

        return true;
    }

    /** @return array{code: string, link: string, delivered: bool} */
    private function issue(TechDevice $device): array
    {
        $code = $this->unlocked(fn (): string => $this->authenticator->issueLoginCode($device));
        $link = url('/capture').'?device='.$device->id;

        return ['code' => $code, 'link' => $link, 'delivered' => $this->notifier->send($device, $code, $link)];
    }

    /**
     * Run a write with the tenant lock lifted and restored after — the roster spans clients.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function unlocked(callable $fn): mixed
    {
        $locked = CurrentSite::id();
        CurrentSite::clear();
        try {
            return $fn();
        } finally {
            if ($locked !== null) {
                CurrentSite::set($locked);
            }
        }
    }
}
