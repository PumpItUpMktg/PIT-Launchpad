<?php

namespace App\Enums;

enum UserRole: string
{
    /** All sites, unrestricted (the platform owner). */
    case Admin = 'admin';

    /** The admin panel, limited to sites carrying a Membership row (else, back-compat, all sites). */
    case Operator = 'operator';

    /** The white-labeled client portal only — never the admin panel. */
    case Client = 'client';

    /** Roles that reach the operator admin panel. */
    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::Operator;
    }
}
