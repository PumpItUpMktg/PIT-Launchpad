<?php

namespace App\Filament\Pages\Guided;

use App\Branding\LogoIntake;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\ServiceSuggester;
use App\Guided\StepGate;
use App\Interview\SiloSeed;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteBranding;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Step 1 · Business & services. Brand + trade + stated services, with the connecting-services
 * suggester ({@see ServiceSuggester}) — confirmed suggestions become stated services (tagged
 * `suggested-confirmed`). Continue persists a {@see SiloSeed} onto the site's blueprint (what
 * Step 3's expansion grounds against) and advances.
 */
class Business extends GuidedPage
{
    use WithFileUploads;

    protected static ?string $slug = 'setup';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Business & services';

    protected string $view = 'filament.guided.business';

    public string $businessName = '';

    public string $trade = '';

    /** The main business phone — the number the site's header, hero and CTA call to. */
    public string $phone = '';

    /** An optional dedicated emergency / after-hours line (falls back to the main phone when blank). */
    public string $emergencyPhone = '';

    /** @var list<string> */
    public array $services = [];

    public string $newService = '';

    /** @var list<array{name: string, why: string, on: bool}> */
    public array $suggestions = [];

    /** The pending logo upload (optional). */
    public mixed $logo = null;

    /** The stored logo for display: url + extracted primary/accent (null until one is stored). */
    public ?array $logoInfo = null;

    public function step(): SetupStep
    {
        return SetupStep::Business;
    }

    public function mount(): void
    {
        parent::mount(); // resolve site + run the gate

        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $this->businessName = (string) $site->brand_name;
        $this->phone = (string) $site->phone;
        $this->emergencyPhone = (string) $site->emergency_phone;
        $blueprint = $this->blueprint($site);
        $seed = ($blueprint !== null && is_array($blueprint->seed)) ? $blueprint->seed : [];
        $this->trade = (string) ($seed['trade'] ?? '');
        $this->services = $this->stringList($seed['anchor_services'] ?? []);
        $this->logoInfo = $this->existingLogo($site);

        if ($this->trade !== '') {
            $this->suggest();
        }
    }

    /**
     * Optional logo — processed the moment it's uploaded (stored to R2, colors extracted, persisted),
     * so Step 3's "Your brand colors" option can appear. Never blocks the step.
     */
    public function updatedLogo(): void
    {
        $site = $this->getSite();
        if ($site === null || ! $this->logo instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate([
            'logo' => ['file', 'mimetypes:image/png,image/jpeg,image/svg+xml,text/plain', 'max:4096'],
        ], [], ['logo' => 'logo']);

        $ext = strtolower($this->logo->getClientOriginalExtension() ?: (string) $this->logo->guessExtension());
        $set = app(LogoIntake::class)->store($site, (string) $this->logo->get(), $ext);

        $this->logo = null;
        $this->logoInfo = $this->displayInfo($set);

        Notification::make()->title('Logo saved.')
            ->body(isset($set['primary']) ? 'Your brand colors are ready as a style option.' : 'Added to your site header.')
            ->success()->send();
    }

    public function removeLogo(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($branding !== null) {
            $set = is_array($branding->logo_set) ? $branding->logo_set : [];
            unset($set['url'], $set['r2_key'], $set['ext'], $set['primary'], $set['accent']);
            $branding->update(['logo_set' => $set]);
        }
        // Drop the logo-colors style choice too — its source is gone.
        $site->update(['use_logo_colors' => false]);
        $this->logoInfo = null;
    }

    public function addService(): void
    {
        $name = trim($this->newService);
        if ($name !== '' && ! in_array($name, $this->services, true)) {
            $this->services[] = $name;
        }
        $this->newService = '';
    }

    public function removeService(int $index): void
    {
        unset($this->services[$index]);
        $this->services = array_values($this->services);
    }

    /** Refresh the connecting-services suggestions for the current trade + stated set. */
    public function suggest(): void
    {
        $rows = app(ServiceSuggester::class)->suggest($this->trade, $this->services);
        $this->suggestions = array_map(fn (array $r) => [...$r, 'on' => false], $rows);
    }

    public function toggleSuggestion(int $index): void
    {
        if (isset($this->suggestions[$index])) {
            $this->suggestions[$index]['on'] = ! $this->suggestions[$index]['on'];
        }
    }

    public function proceed(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        // Confirmed suggestions become stated services (provenance kept on the seed).
        $confirmed = [];
        foreach ($this->suggestions as $s) {
            if ($s['on']) {
                $confirmed[] = $s['name'];
            }
        }
        $anchor = array_values(array_unique([...$this->services, ...$confirmed]));

        if (trim($this->businessName) !== '' && $this->businessName !== $site->brand_name) {
            $site->update(['brand_name' => trim($this->businessName)]);
        }

        // Capture the business phone early (the guided flow never did, so guided-onboarded tenants
        // shipped with no number). Store it canonically on the Site; mirror it onto the primary
        // Location when one exists so the NAP / schema stay consistent.
        $phone = trim($this->phone);
        $emergency = trim($this->emergencyPhone);
        $site->update(['phone' => $phone !== '' ? $phone : null, 'emergency_phone' => $emergency !== '' ? $emergency : null]);
        $this->mirrorPhoneToPrimaryLocation($site, $phone);

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);
        $seed = SiloSeed::fromArray([...($blueprint->seed ?? []), 'trade' => $this->trade, 'anchor_services' => $anchor]);
        $blueprint->update([
            'trade' => $this->trade,
            'seed' => [...$seed->toArray(), 'suggested_confirmed' => $confirmed],
        ]);

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Business);

        Notification::make()->title('Services saved.')->success()->send();
        $this->redirect(SetupStep::ConnectWordpress->pageClass()::getUrl());
    }

    private function blueprint(Site $site): ?SiloBlueprint
    {
        return SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    }

    /**
     * Keep NAP consistent: when the site already has a primary Location with no phone of its own, stamp
     * the business phone onto it so location pages / LocalBusiness schema carry the same number. A
     * Location that already has its own phone is left untouched (multi-location overrides win).
     */
    private function mirrorPhoneToPrimaryLocation(Site $site, string $phone): void
    {
        if ($phone === '') {
            return;
        }

        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->first();

        if ($location !== null && trim((string) $location->phone) === '') {
            $location->forceFill(['phone' => $phone])->save();
        }
    }

    /** @return array{url: string, primary: ?string, accent: ?string}|null */
    private function existingLogo(Site $site): ?array
    {
        $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        $set = is_array($branding?->logo_set) ? $branding->logo_set : [];

        return $this->displayInfo($set);
    }

    /**
     * @param  array<string, mixed>  $set
     * @return array{url: string, primary: ?string, accent: ?string}|null
     */
    private function displayInfo(array $set): ?array
    {
        $url = trim((string) ($set['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        return [
            'url' => $url,
            'primary' => isset($set['primary']) ? (string) $set['primary'] : null,
            'accent' => isset($set['accent']) ? (string) $set['accent'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }
}
