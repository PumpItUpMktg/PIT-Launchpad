<?php

namespace App\Filament\Pages;

use App\Enums\ConnectionProvider;
use App\Enums\UserRole;
use App\Guided\StepGate;
use App\Models\Connection;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\ActiveTenant;
use App\Branding\BrandVariationBuilder;
use App\Operator\Brand\BrandProfile;
use App\Styling\StyleActivator;
use App\Styling\StyleVariation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Brand (operator) — the locked tenant's visual identity in one place: brand name + logo, the resolved
 * look (primary / accent / heading font), and the style-variation picker (the logo-derived "brand colors",
 * the AI/voice recommendation, then the curated variations). Picking a variation is an override; the
 * "Push brand" action applies it to WordPress as a `theme.json` style variation.
 *
 * The block-theme brand surface. Styling is theme.json via {@see StyleActivator} (→ `/style`); this page
 * deliberately does NOT surface the legacy Elementor Global Kit flow ({@see \App\Branding\BrandStudio} →
 * `/brand-kit`), which is quarantined under the Gutenberg-only output contract. Chrome (header/footer) is
 * its own deliberate push (Recover → Push chrome), not bundled here.
 *
 * Tenant-locked: reads the working tenant from {@see ActiveTenant} (no per-page site picker); every write
 * targets the lock, never a passed id. Operator-only.
 *
 * @property-read array<string, mixed>|null $board
 */
class BrandBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationLabel = 'Brand';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $slug = 'brand';

    protected string $view = 'filament.pages.brand-board';

    public ?string $siteId = null;

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
    }

    public function getTitle(): string
    {
        return 'Brand';
    }

    // The shared lp header is the visible heading; suppress Filament's own duplicate h1.
    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /** @return array<string, mixed>|null */
    public function getBoardProperty(): ?array
    {
        return app(BrandProfile::class)->for($this->siteId);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pushBrand')
                ->label('Push brand')
                ->icon('heroicon-o-paint-brush')
                ->visible(fn (): bool => $this->siteId !== null)
                ->requiresConfirmation()
                ->modalHeading('Push the style to WordPress')
                ->modalDescription('Applies the active style variation to the live site as a theme.json style. Header & footer chrome is a separate push (Recover).')
                ->modalSubmitActionLabel('Push brand')
                ->action(fn () => $this->pushBrand()),
        ];
    }

    /**
     * Choose a style variation for the LOCKED tenant. `brand_colors` flips the logo-derived flag; a
     * variation slug sets the override (and clears the logo flag); the write always targets
     * {@see ActiveTenant} — there is no id parameter that could name another tenant. Mirrors
     * {@see \App\Filament\Concerns\ManagesBrandKit::chooseStyle()}.
     */
    public function chooseStyle(string $variation): void
    {
        $site = $this->lockedSite();
        if ($site === null) {
            return;
        }

        if ($variation === 'brand_colors') {
            $site->forceFill(['use_logo_colors' => true])->save();
            Notification::make()->title('Style set to your brand colors.')->success()->send();

            return;
        }

        $picked = $variation === 'auto' ? null : StyleVariation::tryFrom($variation);
        $site->forceFill(['style_variation' => $picked, 'use_logo_colors' => false])->save();

        Notification::make()
            ->title($picked !== null ? "Style set to {$picked->label()}." : 'Using the recommended style.')
            ->success()->send();
    }

    /**
     * Apply the resolved style variation to the locked tenant's WordPress — a theme.json style variation
     * (there is no Elementor Global Kit). Gated on a WP connection; `brand_pushed` is the completion flag.
     */
    public function pushBrand(): void
    {
        $site = $this->lockedSite();
        if ($site === null) {
            return;
        }

        $blocked = $this->pushBlocked($site);
        if ($blocked !== null) {
            Notification::make()->title($blocked)->warning()->send();

            return;
        }

        $result = app(StyleActivator::class)->activate($site);
        $variationValue = (string) ($result['variation'] ?? '');
        $label = $variationValue === BrandVariationBuilder::SLUG
            ? BrandVariationBuilder::TITLE
            : (StyleVariation::tryFrom($variationValue)?->label() ?? 'your style');

        if ($result['updated'] ?? false) {
            app(StepGate::class)->state($site)->update(['brand_pushed' => true]);
            Notification::make()->title("Applied {$label} to the site.")->success()->send();

            return;
        }

        Notification::make()
            ->title('Could not apply the style')
            ->body((string) ($result['error'] ?? 'Try again.'))
            ->danger()->send();
    }

    /** The push gate: a WP app-password connection must exist on the locked tenant. */
    private function pushBlocked(Site $site): ?string
    {
        $connected = Connection::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', ConnectionProvider::WpAppPassword)
            ->exists();

        return $connected ? null : 'Connect WordPress first (Connections).';
    }

    /** Resolve the locked tenant as a writable model — never a passed id. */
    private function lockedSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }
}
