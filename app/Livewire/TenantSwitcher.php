<?php

namespace App\Livewire;

use App\Filament\Pages\Lobby;
use App\Operator\ActiveTenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The topbar tenant chip — the working tenant's logo/name, and nothing else. There is NO in-chrome
 * switcher: under a lock the header shows the current tenant only, plus "Exit site" (tenant-lock
 * remediation). Changing tenant is deliberate friction — Exit site → Lobby → enter — so no page ever
 * carries a dropdown of every tenant the operator can reach. The old all-tenant dropdown was shape E:
 * it put other tenants' names in the chrome of every locked page.
 */
class TenantSwitcher extends Component
{
    /** Leave the locked tenant → the Lobby (which clears {@see ActiveTenant} on mount), where a tenant is picked. */
    public function exitSite(): void
    {
        $this->redirect(Lobby::getUrl(), navigate: false);
    }

    /** @return array{has: bool, name: string, logo_url: ?string} */
    public function getBannerProperty(): array
    {
        return app(ActiveTenant::class)->banner();
    }

    public function render(): View
    {
        return view('livewire.tenant-switcher');
    }
}
