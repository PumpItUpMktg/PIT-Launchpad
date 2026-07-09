<?php

namespace App\Filament\Pages\Guided;

use App\Branding\LogoIntake;
use App\Enums\ProofType;
use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\ServiceSuggester;
use App\Guided\StepGate;
use App\Interview\SiloSeed;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
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

    /** The guarantee / warranty — name + description. Optional; drives the guarantee band. */
    public string $guaranteeName = '';

    public string $guaranteeDescription = '';

    /** Real credentials — each {label, number}. Optional; drives the certifications row. Verbatim. */
    /** @var list<array{label: string, number: string}> */
    public array $certifications = [];

    public string $newCertLabel = '';

    public string $newCertNumber = '';

    /** Business email — optional; stored on the primary Location; drives the Contact page email. */
    public string $email = '';

    /** Business address — from the initial interview; stored on the primary Location. Renders on the
     * site (Contact address + the location-pin map) ONLY when it's a storefront customers visit. */
    public string $address = '';

    /** Whether customers visit the address (a storefront) — gates the address display + map pin. */
    public bool $isStorefront = false;

    /**
     * Business hours — one row per day; optional. Stored on the primary Location in the canonical
     * shape ({day: {open, close} | 'closed'}). The manual fallback until GBP import lands — a
     * connected Google Business Profile will supply these.
     *
     * @var array<string, array{open: string, close: string, closed: bool}>
     */
    public array $hours = [];

    /**
     * Reviews the client pastes from their real review profiles — each {quote, author}. Optional;
     * stored as client-origin testimonial proof (drives the "In their words" sections). The manual
     * fallback until GBP import lands.
     *
     * @var list<array{quote: string, author: string}>
     */
    public array $testimonials = [];

    public string $newTestimonialQuote = '';

    public string $newTestimonialAuthor = '';

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
        $this->loadTrustSignals($site);
        $this->loadContactAndReviews($site);

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

    /** Add a credential (label + optional number). Verbatim — never invented. */
    public function addCertification(): void
    {
        $label = trim($this->newCertLabel);
        if ($label !== '') {
            $this->certifications[] = ['label' => $label, 'number' => trim($this->newCertNumber)];
        }
        $this->newCertLabel = '';
        $this->newCertNumber = '';
    }

    public function removeCertification(int $index): void
    {
        unset($this->certifications[$index]);
        $this->certifications = array_values($this->certifications);
    }

    /** Add a pasted review (quote + optional author). Their real reviews, in their customers' words. */
    public function addTestimonial(): void
    {
        $quote = trim($this->newTestimonialQuote);
        if ($quote !== '') {
            $this->testimonials[] = ['quote' => $quote, 'author' => trim($this->newTestimonialAuthor)];
        }
        $this->newTestimonialQuote = '';
        $this->newTestimonialAuthor = '';
    }

    public function removeTestimonial(int $index): void
    {
        unset($this->testimonials[$index]);
        $this->testimonials = array_values($this->testimonials);
    }

    private const DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

    /** @return array<string, string> day key → display label, for the view's hours grid. */
    public function dayLabels(): array
    {
        return self::DAYS;
    }

    /**
     * Load email + hours from the primary Location and the client-pasted reviews from proof — the
     * manual no-GBP fallback intake this step owns.
     */
    private function loadContactAndReviews(Site $site): void
    {
        $location = $this->primaryLocation($site);
        $this->email = trim((string) ($location->email ?? ''));
        $this->address = trim((string) ($location->address ?? ''));
        $this->isStorefront = (bool) ($location->is_storefront ?? false);

        $stored = is_array($location?->hours) ? $location->hours : [];
        foreach (array_keys(self::DAYS) as $day) {
            $row = $stored[$day] ?? null;
            $this->hours[$day] = [
                'open' => is_array($row) ? (string) ($row['open'] ?? '') : '',
                'close' => is_array($row) ? (string) ($row['close'] ?? '') : '',
                'closed' => $row === 'closed',
            ];
        }

        $this->testimonials = [];
        foreach ($this->clientTestimonials($site) as $item) {
            $payload = is_array($item->payload) ? $item->payload : [];
            $this->testimonials[] = [
                'quote' => trim((string) ($payload['text'] ?? '')),
                'author' => trim((string) ($payload['author'] ?? '')),
            ];
        }
    }

    /**
     * Persist email + hours onto the primary Location (created when needed — name is the brand) and
     * the pasted reviews as CLIENT-ORIGIN testimonial proof. Reviews are the client's own, pasted from
     * their real profiles (client-attested — origin marks the provenance); GBP import replaces this
     * manual path when it lands. Wholesale-replace on each save, so edits and removals stick.
     */
    private function saveContactAndReviews(Site $site): void
    {
        $email = trim($this->email);
        $address = trim($this->address);
        $hours = $this->storedHours();

        $location = $this->primaryLocation($site);
        if ($location === null && ($email !== '' || $address !== '' || $hours !== null)) {
            $location = Location::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->id,
                'name' => trim($this->businessName) !== '' ? trim($this->businessName) : 'Main location',
                'phone' => trim($this->phone) !== '' ? trim($this->phone) : null,
            ]);
        }
        // A changed address invalidates the cached geocode (the Contact pin re-resolves on next push).
        if ($location !== null && $address !== trim((string) $location->address)) {
            $location->forceFill(['latitude' => null, 'longitude' => null, 'geocoded_at' => null]);
        }
        $location?->forceFill([
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
            'is_storefront' => $this->isStorefront,
            'hours' => $hours,
        ])->save();

        // Wholesale-replace the client-origin testimonials (operator/GBP-sourced proof is untouched).
        foreach ($this->clientTestimonials($site) as $item) {
            $item->forceDelete();
        }
        foreach ($this->testimonials as $t) {
            $quote = trim($t['quote']);
            if ($quote === '') {
                continue;
            }
            ProofItem::withoutGlobalScope(SiteScope::class)->create([
                'site_id' => $site->id,
                'type' => ProofType::Testimonial->value,
                'payload' => ['text' => $quote, 'author' => trim($t['author']), 'origin' => 'client'],
                'is_substantiated' => true,
                'evidence' => 'Client-provided — pasted from their own review profile in guided setup.',
            ]);
        }
    }

    /** The canonical stored hours shape ({day: {open, close} | 'closed'}), or null when nothing set. */
    private function storedHours(): ?array
    {
        $out = [];
        foreach (array_keys(self::DAYS) as $day) {
            $row = $this->hours[$day] ?? ['open' => '', 'close' => '', 'closed' => false];
            if (! empty($row['closed'])) {
                $out[$day] = 'closed';
            } elseif (trim((string) $row['open']) !== '' && trim((string) $row['close']) !== '') {
                $out[$day] = ['open' => trim((string) $row['open']), 'close' => trim((string) $row['close'])];
            }
        }

        return $out !== [] ? $out : null;
    }

    /** @return Collection<int, ProofItem> */
    private function clientTestimonials(Site $site)
    {
        return ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('type', ProofType::Testimonial->value)
            ->get()
            ->filter(fn (ProofItem $item): bool => is_array($item->payload) && ($item->payload['origin'] ?? null) === 'client')
            ->values();
    }

    private function primaryLocation(Site $site): ?Location
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->first();
    }

    /** Load the tenant's captured guarantee + certifications from the site narrative. */
    private function loadTrustSignals(Site $site): void
    {
        $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();

        $guarantee = is_array($narrative?->guarantee) ? $narrative->guarantee : [];
        $this->guaranteeName = (string) ($guarantee['name'] ?? '');
        $this->guaranteeDescription = (string) ($guarantee['description'] ?? '');

        $certs = is_array($narrative?->certifications) ? $narrative->certifications : [];
        $this->certifications = [];
        foreach ($certs as $c) {
            if (is_array($c) && trim((string) ($c['label'] ?? '')) !== '') {
                $this->certifications[] = ['label' => trim((string) $c['label']), 'number' => trim((string) ($c['number'] ?? ''))];
            }
        }
    }

    /** Persist the guarantee + certifications verbatim onto the site narrative (null when empty). */
    private function saveTrustSignals(Site $site): void
    {
        $name = trim($this->guaranteeName);
        $guarantee = $name !== '' ? ['name' => $name, 'description' => trim($this->guaranteeDescription)] : null;

        $certs = [];
        foreach ($this->certifications as $c) {
            $label = trim($c['label']);
            if ($label !== '') {
                $certs[] = ['label' => $label, 'number' => trim($c['number'])];
            }
        }

        SiteNarrative::withoutGlobalScope(SiteScope::class)
            ->firstOrCreate(['site_id' => $site->id])
            ->update(['guarantee' => $guarantee, 'certifications' => $certs !== [] ? $certs : null]);
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
        $this->saveContactAndReviews($site);

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);
        $seed = SiloSeed::fromArray([...($blueprint->seed ?? []), 'trade' => $this->trade, 'anchor_services' => $anchor]);
        $blueprint->update([
            'trade' => $this->trade,
            'seed' => [...$seed->toArray(), 'suggested_confirmed' => $confirmed],
        ]);

        $this->saveTrustSignals($site);

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
