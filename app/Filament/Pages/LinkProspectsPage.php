<?php

namespace App\Filament\Pages;

use App\Enums\LinkProspectStatus;
use App\Jobs\FindLinkProspects;
use App\Models\LinkProspect;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Link prospects (§ Links) — the domains that link to the working tenant's local competitors and not to the
 * tenant, found by the competitor intersect, ranked by how many competitors each links to. The operator
 * works the list by hand: open the site, set a status, keep a note. "Find prospects" queues one intersect
 * on the worker. Nothing here contacts anyone or submits anything.
 *
 * @property-read list<LinkProspect> $prospects
 * @property-read array{count: int, last_found_at: Carbon|null, by_status: array<string, int>} $summary
 */
class LinkProspectsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Link prospects';

    protected static ?string $title = 'Link prospects';

    protected static string|\UnitEnum|null $navigationGroup = 'Results';

    protected static ?string $slug = 'link-prospects';

    protected string $view = 'filament.pages.link-prospects';

    public ?string $siteId = null;

    #[Url]
    public string $status = 'open';

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->canOperate() ?? false;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    /** @return list<LinkProspect> */
    public function getProspectsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }
        $q = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId);
        match ($this->status) {
            'open' => $q->whereIn('status', [LinkProspectStatus::New->value, LinkProspectStatus::Contacted->value]),
            'all' => null,
            default => $q->where('status', $this->status),
        };

        return $q->orderByDesc('competitor_count')->orderByDesc('rank')->orderBy('domain')->get()->all();
    }

    /** @return array{count: int, last_found_at: Carbon|null, by_status: array<string, int>} */
    public function getSummaryProperty(): array
    {
        if ($this->siteId === null) {
            return ['count' => 0, 'last_found_at' => null, 'by_status' => []];
        }
        $all = LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->get(['status', 'found_at']);

        return [
            'count' => $all->count(),
            'last_found_at' => $all->max('found_at'),
            'by_status' => $all->countBy(fn (LinkProspect $p): string => $p->status->value)->all(),
        ];
    }

    /** Queue one competitor intersect for the working tenant. */
    public function findProspects(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }
        FindLinkProspects::dispatch((string) $site->id);
        Notification::make()->success()->title('Finding link prospects')
            ->body('One backlink-index call on the worker: the domains linking to the local competitors Town Rank finds above you, and not to you. Reload in a minute.')
            ->send();
    }

    public function setStatus(string $prospectId, string $status): void
    {
        $value = LinkProspectStatus::tryFrom($status);
        $prospect = $this->siteId === null || $value === null ? null
            : LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($prospectId);
        if ($prospect === null) {
            return;
        }
        $prospect->forceFill(['status' => $value])->save();
    }

    public function setNotes(string $prospectId, string $notes): void
    {
        $prospect = $this->siteId === null ? null
            : LinkProspect::withoutGlobalScope(SiteScope::class)->where('site_id', $this->siteId)->find($prospectId);
        if ($prospect === null) {
            return;
        }
        $prospect->forceFill(['notes' => trim($notes) !== '' ? mb_substr(trim($notes), 0, 1000) : null])->save();
    }

    private function site(): ?Site
    {
        return $this->siteId !== null ? Site::withoutGlobalScopes()->find($this->siteId) : null;
    }
}
