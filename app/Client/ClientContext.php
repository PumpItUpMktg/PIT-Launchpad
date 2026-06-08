<?php

namespace App\Client;

use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The current client's panel context: their Account (white-label brand) and the
 * Site in view (switcher selection, session-stored, falling back to their first
 * Site). Everything the §7c dashboard renders is scoped through here.
 */
class ClientContext
{
    public function __construct(
        private readonly ClientAccess $access,
    ) {}

    public function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function account(): ?Account
    {
        $user = $this->user();

        return $user !== null ? $this->access->account($user) : null;
    }

    public function site(): ?Site
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }

        $selected = session('client_site_id');

        return $this->access->currentSite($user, is_string($selected) ? $selected : null);
    }

    /**
     * @return array{name: string, logo_url: string|null, primary: string, accent: string}
     */
    public function branding(): array
    {
        return $this->account()?->branding() ?? [
            'name' => 'Performance',
            'logo_url' => null,
            'primary' => '#0B2545',
            'accent' => '#5BC0EB',
        ];
    }
}
