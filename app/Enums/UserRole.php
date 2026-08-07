<?php

namespace App\Enums;

enum UserRole: string
{
    /** All sites, unrestricted (the platform owner). Part of the internal Super Admin tier. */
    case Admin = 'admin';

    /** The admin panel, limited to sites carrying a Membership row (else, back-compat, all sites). Internal Super Admin tier. */
    case Operator = 'operator';

    /**
     * A CLIENT's own admin — operates a single account's site(s) with guardrails (the day-to-day
     * operating powers, never the backend-correction / credential / user-admin powers). Always
     * membership-scoped; never unrestricted. Distinct from the read-only {@see self::Client} portal.
     */
    case SiteAdmin = 'site_admin';

    /** The white-labeled client portal only — never the admin panel. */
    case Client = 'client';

    /** Roles that reach the operator admin panel. */
    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /**
     * The internal Super Admin tier (Pump It Up team): full authority including backend corrections,
     * credential management, user administration, and tenant lifecycle. Same set as {@see isStaff()}
     * today — a named alias so capability code reads by intent, not by legacy panel-access grouping.
     */
    public function isSuperAdmin(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }

    /** A client-side site Admin (the limited operating role). */
    public function isSiteAdmin(): bool
    {
        return $this === self::SiteAdmin;
    }

    /** Human-facing name for admin surfaces (users list, role pickers, the "viewing as" banner). */
    public function label(): string
    {
        return match ($this) {
            self::Admin, self::Operator => 'Super Admin',
            self::SiteAdmin => 'Site Admin',
            self::Client => 'Client',
        };
    }
}
