<?php

namespace App\Filament\Pages\Operate;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Chrome\SiteProfileAssembler;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Operate · Header menu builder — arrange the two header menus by moving items up/down.
 *
 *  - MAIN MENU (the primary row): the company pages + Areas We Serve. Reorder only (membership is the
 *    pages that exist); order persists on `nav_order`, read by {@see SiteProfileAssembler::mainNav}.
 *  - SERVICES BAR (the slim row beneath): the service/hub pages pinned into the header
 *    (`nav_featured`). Add / remove / reorder; with none pinned the header auto-shows the top-8.
 *
 * Reached from the Portfolio's "Header menu" action (sets the tenant and lands here). Changes take
 * effect on the next "Sync header & footer" push. Working-tenant scoped.
 *
 * @property-read array{main: list<array<string,string>>, services: list<array<string,string>>, available: list<array<string,string>>} $menus
 */
class HeaderMenu extends OperatePage
{
    protected static ?string $slug = 'operate/header-menu';

    protected static ?string $navigationLabel = 'Header menu';

    protected string $view = 'filament.operate.header-menu';

    // Reached from the Portfolio action, not a daily sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public ?string $siteId = null;

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
        }
    }

    /**
     * @return array{main: list<array<string,string>>, services: list<array<string,string>>, available: list<array<string,string>>}
     */
    public function getMenusProperty(): array
    {
        if ($this->siteId === null) {
            return ['main' => [], 'services' => [], 'available' => []];
        }

        $row = fn (Content $c): array => ['id' => (string) $c->id, 'title' => (string) $c->title, 'slug' => '/'.ltrim((string) $c->slug, '/')];

        return [
            'main' => array_map($row, $this->mainPages()->all()),
            'services' => array_map($row, $this->servicePages()->all()),
            'available' => array_map($row, $this->availableServicePages()->all()),
        ];
    }

    // ── Reorder (move up / down) ─────────────────────────────────────────────

    public function moveMainUp(string $id): void
    {
        $this->move($this->mainPages()->pluck('id')->map(fn ($v): string => (string) $v)->all(), $id, -1);
    }

    public function moveMainDown(string $id): void
    {
        $this->move($this->mainPages()->pluck('id')->map(fn ($v): string => (string) $v)->all(), $id, 1);
    }

    public function moveServiceUp(string $id): void
    {
        $this->move($this->servicePages()->pluck('id')->map(fn ($v): string => (string) $v)->all(), $id, -1);
    }

    public function moveServiceDown(string $id): void
    {
        $this->move($this->servicePages()->pluck('id')->map(fn ($v): string => (string) $v)->all(), $id, 1);
    }

    // ── Services-bar membership ──────────────────────────────────────────────

    public function addService(string $id): void
    {
        $content = $this->ownedPage($id);
        if ($content === null) {
            return;
        }

        $max = (int) $this->servicePages()->max('nav_order');
        $content->forceFill(['nav_featured' => true, 'nav_order' => $max + 1])->save();
        Notification::make()->success()->title('Added to the services bar')
            ->body('Reorder it with the arrows; push it live with "Sync header & footer".')->send();
    }

    public function removeService(string $id): void
    {
        $content = $this->ownedPage($id);
        if ($content === null) {
            return;
        }

        $content->forceFill(['nav_featured' => false])->save();
        Notification::make()->success()->title('Removed from the services bar')->send();
    }

    /**
     * Persist a new order for a menu: sequential `nav_order` 1..n after swapping the item one step.
     *
     * @param  list<string>  $orderedIds
     */
    private function move(array $orderedIds, string $id, int $direction): void
    {
        $i = array_search($id, $orderedIds, true);
        if ($i === false) {
            return;
        }
        $j = $i + $direction;
        if ($j < 0 || $j >= count($orderedIds)) {
            return;
        }

        [$orderedIds[$i], $orderedIds[$j]] = [$orderedIds[$j], $orderedIds[$i]];

        foreach ($orderedIds as $pos => $cid) {
            Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $this->siteId)
                ->whereKey($cid)
                ->update(['nav_order' => $pos + 1]);
        }
    }

    /** @return Collection<int, Content> */
    private function mainPages(): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('slug', SiteProfileAssembler::MAIN_NAV_SLUGS)
            ->orderByRaw('nav_order is null')
            ->orderBy('nav_order')
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, Content> */
    private function servicePages(): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Page->value)
            ->where('nav_featured', true)
            ->whereNotNull('slug')
            ->orderByRaw('nav_order is null')
            ->orderBy('nav_order')
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, Content> */
    private function availableServicePages(): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Page->value)
            ->whereIn('page_type', [PageType::Service->value, PageType::Hub->value])
            ->where('nav_featured', false)
            ->whereNotNull('slug')
            ->orderBy('title')
            ->get();
    }

    private function ownedPage(string $id): ?Content
    {
        return $this->siteId === null ? null : Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->where('kind', ContentKind::Page->value)
            ->whereKey($id)
            ->first();
    }
}
