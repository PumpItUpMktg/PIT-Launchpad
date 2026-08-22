<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MetricSyncRun;
use App\Models\User;

/**
 * Sync runs are internal operational records (§ Client Dashboard v1) — operator-only in full. Clients never
 * see raw sync bookkeeping; the "data through {date}" they do see is surfaced by the dashboard view-model,
 * not by reading this table directly.
 */
class MetricSyncRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function view(User $user, MetricSyncRun $run): bool
    {
        return $this->isOperator($user);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function update(User $user, MetricSyncRun $run): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, MetricSyncRun $run): bool
    {
        return $this->isOperator($user);
    }

    private function isOperator(User $user): bool
    {
        return $user->role === UserRole::Operator;
    }
}
