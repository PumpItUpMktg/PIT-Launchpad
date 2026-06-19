<?php

namespace App\Client;

use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Records the client's sign-off on the proposed page plan (§7c). Ownership-gated through
 * {@see ClientAccess} — a client can only approve a Site under their own Account. This is a
 * recorded approval (who/when on the blueprint), distinct from the operator's Finalize hard
 * gate, which still owns what actually gets built.
 */
class PlanApproval
{
    public function __construct(
        private readonly ClientAccess $access,
    ) {}

    /** Stamp the client's sign-off; false if they don't own the Site or it has no blueprint. */
    public function approve(Site $site, User $user): bool
    {
        if (! $this->access->canSee($user, $site)) {
            return false;
        }

        $blueprint = $this->blueprint($site);
        if ($blueprint === null) {
            return false;
        }

        $blueprint->update(['client_approved_at' => now(), 'client_approved_by' => $user->id]);

        return true;
    }

    /**
     * @return array{approved: bool, at: Carbon|null}
     */
    public function status(Site $site): array
    {
        $blueprint = $this->blueprint($site);

        return [
            'approved' => $blueprint?->isClientApproved() ?? false,
            'at' => $blueprint?->client_approved_at,
        ];
    }

    private function blueprint(Site $site): ?SiloBlueprint
    {
        return SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->latest('created_at')->first();
    }
}
