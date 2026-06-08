<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Connection;
use App\Models\User;

/**
 * Least-privilege access to per-tenant credentials: only operators may view,
 * reveal, or rotate them. Clients never see credential fields. Auto-discovered
 * by Laravel for the Connection model.
 */
class ConnectionPolicy
{
    public function view(User $user, Connection $connection): bool
    {
        return $this->isOperator($user);
    }

    public function reveal(User $user, Connection $connection): bool
    {
        return $this->isOperator($user);
    }

    public function rotate(User $user, Connection $connection): bool
    {
        return $this->isOperator($user);
    }

    private function isOperator(User $user): bool
    {
        return $user->role === UserRole::Operator;
    }
}
