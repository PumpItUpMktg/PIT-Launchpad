<?php

namespace App\Filament\Pages\Guided;

use App\Branding\BrandStudio;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\StepGate;
use App\Models\Scopes\SiteScope;
use App\Models\SiteBranding;
use Filament\Notifications\Notification;

/**
 * Step 3 · Brand — palette / typography → brand kit → push. Folds the standalone brand step into
 * the flow ({@see BrandStudio}: generate + save + push to the site's Elementor Global Kit). The
 * push is reachable only once step 2 set `deps_ready` (Brand's prerequisite), so the brand kit
 * can't be pushed to an unprepared WordPress — the /brand-kit 404 can't recur. `brand_pushed` is
 * the completion flag.
 *
 * @property-read array{palette: array<string, string>, typography: array<string, string>}|null $branding
 * @property-read bool $pushed
 */
class Brand extends GuidedPage
{
    protected static ?string $slug = 'setup/brand';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Brand';

    protected string $view = 'filament.guided.brand';

    public function step(): SetupStep
    {
        return SetupStep::Brand;
    }

    /**
     * @return array{palette: array<string, string>, typography: array<string, string>}|null
     */
    public function getBrandingProperty(): ?array
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($branding === null || ! is_array($branding->palette)) {
            return null;
        }

        return [
            'palette' => $branding->palette,
            'typography' => is_array($branding->typography) ? $branding->typography : [],
        ];
    }

    public function getPushedProperty(): bool
    {
        $site = $this->getSite();

        return $site !== null && app(StepGate::class)->state($site)->brand_pushed;
    }

    /** Generate a brand (industry-grounded) and save it — the preview before pushing. */
    public function generate(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $studio = app(BrandStudio::class);
        $brand = $studio->generate($site, []); // industry resolved from the site
        $studio->save($site, $brand->palette, $brand->typography);

        Notification::make()->title('Brand generated — review, then push.')->success()->send();
    }

    /** Push the saved brand kit to the prepped WordPress (gated on deps_ready). */
    public function pushBrand(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $gate = app(StepGate::class);
        if (! $gate->state($site)->deps_ready) {
            Notification::make()->title('Connect & prep WordPress first.')->warning()->send();

            return;
        }

        $result = app(BrandStudio::class)->push($site);
        if ($result['updated'] ?? false) {
            $gate->state($site)->update(['brand_pushed' => true]);
            Notification::make()->title('Brand kit pushed to WordPress.')->success()->send();

            return;
        }

        Notification::make()->title('Brand push failed')->body((string) ($result['error'] ?? 'Try again.'))->danger()->send();
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        if (! $this->pushed) {
            Notification::make()->title('Push your brand kit first.')->warning()->send();

            return;
        }

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Brand);
        $this->redirect(SetupStep::Territory->pageClass()::getUrl());
    }
}
