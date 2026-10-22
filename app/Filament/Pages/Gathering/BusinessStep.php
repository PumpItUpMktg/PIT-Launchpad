<?php

namespace App\Filament\Pages\Gathering;

use App\Enums\LinkProspectKind;
use App\Gathering\Provenance;
use App\Integrations\Places\PlacesProvider;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Interview\SiloSeed;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Publishing\Chrome\SiteProfileAssembler;
use App\Publishing\ConnectionGate;
use Filament\Notifications\Notification;
use Throwable;

/**
 * New Setup · Step 1 — Business: GBP import + identity + trust facts.
 *
 * - Bulk GBP import: one URL/name per line → each resolves via Places → a reviewable list;
 *   every resolved entry creates a Location SKELETON (same Location records the rest of the
 *   platform uses). Failures stay listed, editable and re-resolvable — never blocking.
 * - Trust facts (license, insured, years, warranty, guarantees) live on the Site record —
 *   manual entry here; the interview can seed them (a save flips seeded → confirmed).
 */
class BusinessStep extends GatheringPage
{
    protected static ?string $slug = 'setup2/business';

    protected static ?string $navigationLabel = 'Business';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.gathering.business-step';

    // Identity.
    public string $brandName = '';

    public string $phone = '';

    public string $emergencyPhone = '';

    public string $domainUrl = '';

    // Corporate / site-wide address — the NAP address for the ENTIRE site (header/footer chrome),
    // distinct from each physical location's own address (those come from the GBP import / Locations step).
    public string $corporateStreet = '';

    public string $corporateCity = '';

    public string $corporateState = '';

    public string $corporatePostalCode = '';

    // The trade — the SiloBlueprint seed the structure engine builds the silo tree from
    // (previously captured only by the old guided Business step / old Owner Interview).
    public string $trade = '';

    // Trust facts.
    public string $licenseNumber = '';

    public string $insured = 'unknown'; // yes | no | unknown

    public string $yearsInBusiness = '';

    public string $warrantyProgram = '';

    public string $guarantees = '';

    // Local link opportunities (§ Links) — the inputs the discovery pass and the outreach guardrails read.
    public string $brands = '';            // one per line or comma-separated

    /** @var list<string> */
    public array $linkLanes = [];

    public string $sponsorshipBudgetMin = '';

    public string $sponsorshipBudgetMax = '';

    public string $outreachMonthlyCap = '20';

    public string $outreachFromEmail = '';

    public string $outreachFromName = '';

    // Bulk GBP import.
    public string $bulkInput = '';

    /** @var list<array{query: string, status: string, name: string, address: string, place_id: string|null, message: string|null}> */
    public array $bulkResults = [];

    protected function afterSiteResolved(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $this->brandName = (string) $site->brand_name;
        $this->trade = (string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->value('trade') ?? '');
        $this->phone = (string) ($site->phone ?? '');
        $this->emergencyPhone = (string) ($site->emergency_phone ?? '');
        $this->domainUrl = (string) ($site->domain_url ?? '');
        $this->corporateStreet = (string) ($site->corporate_street ?? '');
        $this->corporateCity = (string) ($site->corporate_city ?? '');
        $this->corporateState = (string) ($site->corporate_state ?? '');
        $this->corporatePostalCode = (string) ($site->corporate_postal_code ?? '');
        $this->licenseNumber = (string) ($site->license_number ?? '');
        $this->insured = $site->insured === null ? 'unknown' : ($site->insured ? 'yes' : 'no');
        $this->yearsInBusiness = $site->years_in_business !== null ? (string) $site->years_in_business : '';
        $this->warrantyProgram = (string) ($site->warranty_program ?? '');
        $this->guarantees = (string) ($site->guarantees ?? '');
        $this->brands = implode("\n", is_array($site->brands) ? $site->brands : []);
        $this->linkLanes = $site->link_lanes !== null && $site->link_lanes !== []
            ? $site->link_lanes
            : array_map(fn (LinkProspectKind $k): string => $k->value, LinkProspectKind::lanes());
        $this->sponsorshipBudgetMin = $site->sponsorship_budget_min !== null ? (string) $site->sponsorship_budget_min : '';
        $this->sponsorshipBudgetMax = $site->sponsorship_budget_max !== null ? (string) $site->sponsorship_budget_max : '';
        $this->outreachMonthlyCap = (string) ($site->outreach_monthly_cap ?? 20);
        $this->outreachFromEmail = (string) ($site->outreach_from_email ?? '');
        $this->outreachFromName = (string) ($site->outreach_from_name ?? '');
    }

    public function save(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $site->forceFill([
            'brand_name' => trim($this->brandName) !== '' ? trim($this->brandName) : $site->brand_name,
            'phone' => trim($this->phone) !== '' ? trim($this->phone) : null,
            'emergency_phone' => trim($this->emergencyPhone) !== '' ? trim($this->emergencyPhone) : null,
            'domain_url' => trim($this->domainUrl) !== '' ? trim($this->domainUrl) : null,
            'corporate_street' => trim($this->corporateStreet) !== '' ? trim($this->corporateStreet) : null,
            'corporate_city' => trim($this->corporateCity) !== '' ? trim($this->corporateCity) : null,
            'corporate_state' => trim($this->corporateState) !== '' ? trim($this->corporateState) : null,
            'corporate_postal_code' => trim($this->corporatePostalCode) !== '' ? trim($this->corporatePostalCode) : null,
            'license_number' => trim($this->licenseNumber) !== '' ? trim($this->licenseNumber) : null,
            'insured' => match ($this->insured) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            'years_in_business' => trim($this->yearsInBusiness) !== '' ? (int) $this->yearsInBusiness : null,
            'warranty_program' => trim($this->warrantyProgram) !== '' ? trim($this->warrantyProgram) : null,
            'guarantees' => trim($this->guarantees) !== '' ? trim($this->guarantees) : null,
            'brands' => $this->brandList(),
            'link_lanes' => $this->laneList(),
            'sponsorship_budget_min' => trim($this->sponsorshipBudgetMin) !== '' ? max(0, (int) $this->sponsorshipBudgetMin) : null,
            'sponsorship_budget_max' => trim($this->sponsorshipBudgetMax) !== '' ? max(0, (int) $this->sponsorshipBudgetMax) : null,
            // The hard ceiling on outreach emails per month — never above 20, never below 1.
            'outreach_monthly_cap' => max(1, min(20, (int) ($this->outreachMonthlyCap !== '' ? $this->outreachMonthlyCap : 20))),
            'outreach_from_email' => $this->fromEmail($site),
            'outreach_from_name' => trim($this->outreachFromName) !== '' ? trim($this->outreachFromName) : null,
        ])->save();

        // The trade seeds the structure engine (SiloBlueprint) — same write as the old guided
        // Business step, so silo-gen has its seed without the old Owner Interview.
        if (trim($this->trade) !== '') {
            $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);
            $seed = SiloSeed::fromArray([...($blueprint->seed ?? []), 'trade' => trim($this->trade)]);
            $blueprint->update(['trade' => trim($this->trade), 'seed' => [...$seed->toArray(), 'suggested_confirmed' => ($blueprint->seed['suggested_confirmed'] ?? [])]]);
            $this->confirmSeeded($blueprint, ['trade']);
        }

        // A review-surface save confirms interview-seeded fields; manual fields stay rowless.
        $this->confirmSeeded($site, ['license_number', 'insured', 'years_in_business', 'warranty_program', 'guarantees']);

        // The corporate NAP is what the header/footer chrome renders. That chrome is pushed to WordPress
        // as a one-time profile — so editing the phone/address here without a re-push leaves the LIVE
        // header/footer showing whatever was pushed before (a physical location's NAP, if corporate was
        // captured later). Re-push now, for a connected site, so the chrome tracks the corporate NAP.
        $synced = $this->resyncChrome($site->fresh());

        Notification::make()->success()->title('Business saved')
            ->body($synced ? 'Header & footer chrome refreshed with the corporate NAP.' : null)
            ->send();
    }

    /**
     * Best-effort: re-push the site-wide header/footer chrome so it reflects the just-saved corporate
     * NAP. Only for a site with a present, non-compromised WordPress connection (a not-yet-connected
     * site gets its chrome at launch/style activation). Never blocks the save — a push failure is logged.
     */
    private function resyncChrome(?Site $site): bool
    {
        if ($site === null || ! app(ConnectionGate::class)->hasVerifiedWordpress($site->id)) {
            return false;
        }

        try {
            $profile = app(SiteProfileAssembler::class)->assemble($site);
            $result = app(WordpressClientFactory::class)->forSite($site)->pushSiteProfile($profile);

            return ! empty($result['updated']);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** The trade field's provenance chip state ('seeded'|'confirmed'|null), from the blueprint. */
    public function getTradeProvenanceProperty(): ?string
    {
        $site = $this->getSite();
        if ($site === null) {
            return null;
        }

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->first();

        return $blueprint === null ? null : app(Provenance::class)->state($blueprint, 'trade')?->value;
    }

    /** Resolve every non-empty line through Places into a reviewable list (nothing saved yet). */
    public function resolveBulk(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $lines = collect(preg_split('/\r?\n/', $this->bulkInput) ?: [])
            ->map(fn ($l) => trim((string) $l))
            ->filter()
            ->values();

        if ($lines->isEmpty()) {
            Notification::make()->warning()->title('Paste one GBP URL or business name per line first.')->send();

            return;
        }

        $this->bulkResults = $lines->map(fn (string $line) => $this->resolveLine($line))->all();

        $failed = collect($this->bulkResults)->where('status', 'failed')->count();
        Notification::make()->success()
            ->title(count($this->bulkResults).' line(s) resolved'.($failed > 0 ? " — {$failed} failed (editable below, never blocking)" : ''))
            ->send();
    }

    /** Edit + retry one failed line in place. */
    public function retryLine(int $index): void
    {
        if (! isset($this->bulkResults[$index])) {
            return;
        }

        $this->bulkResults[$index] = $this->resolveLine(trim((string) $this->bulkResults[$index]['query']));
    }

    /** Create a Location skeleton per resolved row (idempotent by place_id). */
    public function importResolved(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $created = 0;
        foreach ($this->bulkResults as $i => $row) {
            if ($row['status'] !== 'resolved' || $row['place_id'] === null) {
                continue;
            }

            $exists = Location::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('place_id', $row['place_id'])
                ->exists();
            if ($exists) {
                $this->bulkResults[$i]['status'] = 'imported';

                continue;
            }

            $details = app(PlacesProvider::class)->details($row['place_id']);
            $location = new Location;
            $location->forceFill([
                'site_id' => $site->id,
                'name' => $details->name ?? $row['name'],
                'address' => $details->address ?? ($row['address'] !== '' ? $row['address'] : null),
                'address_components' => $details?->addressComponents,
                'phone' => $details?->phone,
                'lat' => $details?->lat,
                'lng' => $details?->lng,
                'gbp_url' => $details?->gbpUrl,
                'place_id' => $row['place_id'],
                'hours' => $details?->hours,
            ])->save();

            $this->bulkResults[$i]['status'] = 'imported';
            $created++;
        }

        Notification::make()->success()
            ->title($created > 0 ? "{$created} location skeleton(s) created" : 'Nothing new to import')
            ->body('Review served towns and market notes on the Locations step.')
            ->send();
    }

    /** The Business form persists before moving on. */
    public function savesOnContinue(): bool
    {
        return true;
    }

    protected function beforeContinue(): void
    {
        $this->save();
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $site = $this->getSite();
        if ($site === null || trim((string) $site->brand_name) === '') {
            return ['state' => 'empty', 'label' => 'Empty'];
        }

        $trust = $site->license_number !== null || $site->insured !== null
            || $site->years_in_business !== null || $site->warranty_program !== null || $site->guarantees !== null;

        return $trust
            ? ['state' => 'complete', 'label' => 'Complete']
            : ['state' => 'attention', 'label' => 'Needs attention — no trust facts yet'];
    }

    /**
     * @return array{query: string, status: string, name: string, address: string, place_id: string|null, message: string|null}
     */
    private function resolveLine(string $line): array
    {
        try {
            $candidates = app(PlacesProvider::class)->search($line);
        } catch (Throwable $e) {
            return ['query' => $line, 'status' => 'failed', 'name' => '', 'address' => '', 'place_id' => null, 'message' => $e->getMessage()];
        }

        $best = $candidates[0] ?? null;
        if ($best === null) {
            return ['query' => $line, 'status' => 'failed', 'name' => '', 'address' => '', 'place_id' => null, 'message' => 'No match found — edit the line and retry.'];
        }

        return [
            'query' => $line,
            'status' => 'resolved',
            'name' => $best->name,
            'address' => $best->address,
            'place_id' => $best->placeId,
            'message' => null,
        ];
    }

    /** @return list<string>|null */
    private function brandList(): ?array
    {
        $out = [];
        foreach (preg_split('/[\n,]+/', $this->brands) ?: [] as $b) {
            $b = trim((string) $b);
            if ($b !== '' && ! in_array($b, $out, true)) {
                $out[] = $b;
            }
        }

        return $out !== [] ? $out : null;
    }

    /** @return list<string>|null null = every lane */
    private function laneList(): ?array
    {
        $known = array_map(fn (LinkProspectKind $k): string => $k->value, LinkProspectKind::lanes());
        $chosen = array_values(array_intersect($known, array_map('strval', $this->linkLanes)));

        return count($chosen) === count($known) || $chosen === [] ? null : $chosen;
    }

    /**
     * The outreach sender: must be an address on the tenant's OWN domain — mail never goes out from anywhere
     * else. Anything else is dropped (and the field shows empty on the next load, which is the signal).
     */
    private function fromEmail(Site $site): ?string
    {
        $email = strtolower(trim($this->outreachFromEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $domain = strtolower((string) preg_replace('/^www\./', '', (string) parse_url((string) $site->domain_url, PHP_URL_HOST)));
        $at = (string) substr($email, (int) strrpos($email, '@') + 1);

        return $domain !== '' && ($at === $domain || str_ends_with($at, '.'.$domain)) ? $email : null;
    }
}
