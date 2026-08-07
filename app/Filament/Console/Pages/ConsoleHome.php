<?php

namespace App\Filament\Console\Pages;

use App\Security\Capability;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The Operations Console landing page: the home of the stand-alone `console` panel. It states the
 * signed-in user's tier and lays out — grouped by operate / recover / administration — exactly which
 * capabilities they hold vs which are Super-Admin-only. This is the visible face of the two-tier split:
 * a Site Admin sees the operate row lit and the recover/admin rows locked; a Super Admin sees them all.
 * The operating pages themselves land in later stages; this is the anchor they hang off.
 */
class ConsoleHome extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Home';

    protected static ?string $slug = '/';

    protected string $view = 'filament.console.home';

    public function getTitle(): string
    {
        return 'Operations Console';
    }

    /**
     * @return array{tier: string, is_super: bool, groups: list<array{key: string, label: string, blurb: string, items: list<array{label: string, held: bool}>}>}
     */
    public function getBoardProperty(): array
    {
        $user = Auth::user();

        $byGroup = [];
        foreach (Capability::cases() as $capability) {
            $byGroup[$capability->group()][] = [
                'label' => $capability->label(),
                'held' => $user?->hasCapability($capability) ?? false,
            ];
        }

        $meta = [
            'operate' => ['label' => 'Operate', 'blurb' => 'Run the site day to day.'],
            'recover' => ['label' => 'Recover', 'blurb' => 'Clear stuck state. Super Admin only.'],
            'admin' => ['label' => 'Administration', 'blurb' => 'Credentials, users, tenants. Super Admin only.'],
        ];

        $groups = [];
        foreach ($meta as $key => $info) {
            $groups[] = [
                'key' => $key,
                'label' => $info['label'],
                'blurb' => $info['blurb'],
                'items' => $byGroup[$key] ?? [],
            ];
        }

        return [
            'tier' => $user?->role->label() ?? '—',
            'is_super' => $user?->isSuperAdmin() ?? false,
            'groups' => $groups,
        ];
    }
}
