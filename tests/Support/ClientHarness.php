<?php

namespace Tests\Support;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;

/**
 * Builds a client tenant: an Account (white-label brand) with a Site and a
 * client User wired via Membership.
 */
class ClientHarness
{
    /**
     * @param  array<string, mixed>  $accountAttributes
     * @return array{user: User, account: Account, site: Site}
     */
    public static function make(array $accountAttributes = []): array
    {
        $account = Account::factory()->create($accountAttributes);
        $site = Site::factory()->create(['account_id' => $account->id]);
        $user = User::factory()->create(['role' => UserRole::Client]);

        Membership::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'site_id' => $site->id,
            'role' => UserRole::Client,
        ]);

        return ['user' => $user, 'account' => $account, 'site' => $site];
    }
}
