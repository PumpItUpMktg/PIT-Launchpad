<?php

namespace App\Filament\Pages\Gathering;

use App\Filament\Resources\ServiceResource;
use App\Gathering\ServiceEnricher;
use App\Guided\ServiceSuggester;
use App\Jobs\EnrichThinServices;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * New Setup · Step 4 — Services review surface. The stated list with add/remove; per-service
 * enrichment edits open THE service form from {@see ServiceResource::enrichmentComponents()} —
 * the exact contract from the service-pages relay, never a second copy. Interview-seeded fields
 * carry the "from interview" chip on the card; saving the modal confirms them.
 *
 * The trade-driven suggester (the old guided Business step's "commonly also offered" list —
 * {@see ServiceSuggester}, advisory, service-intent only) rides here too: suggestions come
 * from the blueprint trade the Business step / interview captured, and an added suggestion
 * becomes a stated service with its provenance kept on the seed (silo-gen grounding).
 *
 * @property-read Collection<int, Service> $services
 * @property-read string $trade
 */
class ServicesStep extends GatheringPage
{
    /** The enrichment fields the seeded chip + confirm-on-save track. */
    public const ENRICHMENT_FIELDS = ['name', 'short_description', 'symptoms', 'scope_items', 'process_steps', 'cost_factors', 'price_range', 'comparison', 'warranty_applicable', 'description'];

    protected static ?string $slug = 'setup2/services';

    protected static ?string $navigationLabel = 'Services';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.gathering.services-step';

    public string $newService = '';

    /** @var list<array{name: string, why: string}> */
    public array $suggestions = [];

    protected function afterSiteResolved(): void
    {
        $this->reset(['suggestions', 'newService']);
    }

    /** @return Collection<int, Service> */
    public function getServicesProperty(): Collection
    {
        if ($this->siteId === null) {
            return new Collection;
        }

        return Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderBy('name')
            ->get();
    }

    public function addService(): void
    {
        $site = $this->getSite();
        $name = trim($this->newService);
        if ($site === null || $name === '') {
            return;
        }

        $service = new Service;
        $service->forceFill(['site_id' => $site->id, 'name' => $name])->save();
        $this->newService = '';

        Notification::make()->success()->title("'{$name}' added — enrich it when ready.")->send();
    }

    /** The blueprint trade the suggester keys on (captured on the Business step / interview). */
    public function getTradeProperty(): string
    {
        return $this->siteId === null ? '' : (string) (SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->value('trade') ?? '');
    }

    /**
     * Refresh the "commonly also offered" suggestions for the current trade + stated set — the
     * old guided Business step's connecting-services list, advisory and service-intent only.
     */
    public function suggest(): void
    {
        $trade = $this->getTradeProperty();
        if ($trade === '') {
            Notification::make()->warning()
                ->title('No trade captured yet')
                ->body('Set the trade on the Business step (or run the interview) — suggestions are keyed on it.')
                ->send();

            return;
        }

        $this->suggestions = app(ServiceSuggester::class)->suggest($trade, $this->getServicesProperty()->pluck('name')->all());

        if ($this->suggestions === []) {
            Notification::make()->info()->title('No further suggestions — the stated list already covers the trade.')->send();
        }
    }

    /** Confirm a suggestion: it becomes a stated service, its provenance kept on the seed. */
    public function addSuggestion(int $index): void
    {
        $site = $this->getSite();
        $row = $this->suggestions[$index] ?? null;
        if ($site === null || $row === null) {
            return;
        }

        $service = new Service;
        $service->forceFill(['site_id' => $site->id, 'name' => $row['name']])->save();

        unset($this->suggestions[$index]);
        $this->suggestions = array_values($this->suggestions);

        // Same provenance the guided flow kept: a confirmed suggestion is recorded on the seed
        // so silo-gen grounding knows it was suggested (not owner-stated) — never lost on a
        // Business-step save (which preserves suggested_confirmed).
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
        if ($blueprint !== null && is_array($blueprint->seed)) {
            $seed = $blueprint->seed;
            $seed['suggested_confirmed'] = array_values(array_unique([...(array) ($seed['suggested_confirmed'] ?? []), $row['name']]));
            $blueprint->update(['seed' => $seed]);
        }

        Notification::make()->success()->title("'{$row['name']}' added — enrich it when ready.")->send();
    }

    public function dismissSuggestion(int $index): void
    {
        unset($this->suggestions[$index]);
        $this->suggestions = array_values($this->suggestions);
    }

    /**
     * The opt-in AI enrichment call — fills the service's EMPTY enrichment fields with generic
     * trade knowledge as SEEDED values (manual entry is never overwritten; price/warranty/
     * comparison stay owner-supplied). The operator reviews in Enrich; saving confirms.
     */
    public function aiEnrich(string $serviceId): void
    {
        $site = $this->getSite();
        $service = $this->owned($serviceId);
        if ($site === null || $service === null) {
            return;
        }

        $filled = app(ServiceEnricher::class)->enrich($site, $service);

        if ($filled === null) {
            Notification::make()->warning()
                ->title('AI enrichment unavailable right now')
                ->body('Nothing was changed — try again, or fill the fields manually via Enrich.')
                ->send();

            return;
        }

        if ($filled === []) {
            Notification::make()->info()->title("'{$service->name}' has no empty fields — edit it via Enrich.")->send();

            return;
        }

        Notification::make()->success()
            ->title("'{$service->name}' drafted — ".count($filled).' field(s) filled')
            ->body('Generic trade knowledge only (no prices or guarantees). Review in Enrich and save to confirm.')
            ->send();
    }

    /**
     * Bulk enrich every THIN service (no symptoms/scope/process/cost) in one action — the
     * page-board "Needs enrichment" badges in bulk. One Claude call per service, so it runs on the
     * worker ({@see EnrichThinServices}); this counts what's queued and returns immediately.
     */
    public function aiEnrichAll(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $thin = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Service $service): bool => $service->isThin())
            ->count();

        if ($thin === 0) {
            Notification::make()->info()->title('Nothing to enrich — every service already has its details.')->send();

            return;
        }

        EnrichThinServices::dispatch($site->id);

        Notification::make()->success()
            ->title("Enriching {$thin} thin service(s) in the background")
            ->body('Generic trade knowledge only (no prices or guarantees). Refresh in a minute — the "needs enrichment" flags clear as each fills; review each in Enrich, then regenerate its page.')
            ->send();
    }

    public function removeService(string $serviceId): void
    {
        $service = $this->owned($serviceId);
        if ($service === null) {
            return;
        }

        $service->delete();
        Notification::make()->success()->title("'{$service->name}' removed.")->send();
    }

    /** The per-service enrichment modal — the ServiceResource form, reused wholesale. */
    public function enrich(): Action
    {
        return Action::make('enrich')
            ->label('Enrich')
            ->modalHeading(fn (array $arguments): string => 'Enrich · '.($this->owned((string) ($arguments['service'] ?? ''))->name ?? 'service'))
            ->fillForm(function (array $arguments): array {
                $service = $this->owned((string) ($arguments['service'] ?? ''));

                return $service === null ? [] : $this->enrichmentFormState($service);
            })
            ->schema(ServiceResource::enrichmentComponents())
            ->action(function (array $data, array $arguments): void {
                $service = $this->owned((string) ($arguments['service'] ?? ''));
                if ($service === null) {
                    return;
                }

                $service->forceFill(array_intersect_key($data, array_flip(self::ENRICHMENT_FIELDS)))->save();
                // Reviewing the whole form and saving confirms every seeded field on it.
                $this->confirmSeeded($service, self::ENRICHMENT_FIELDS);

                Notification::make()->success()->title("'{$service->name}' saved")->send();
            });
    }

    /**
     * A form-safe snapshot for the enrich modal: coerce every field into the exact shape the schema
     * expects, so a legacy/foreign data shape can never break the modal render ("Error while loading
     * page"). The simple() repeaters (symptoms / scope / process / cost / comparison points) need FLAT
     * lists of strings — a stored null, a bare string, or a nested `[{item: …}]` list would otherwise
     * throw during render; price_range / comparison must be arrays; toggles bools; text strings.
     *
     * @return array<string, mixed>
     */
    private function enrichmentFormState(Service $service): array
    {
        return [
            'name' => (string) $service->name,
            'short_description' => (string) $service->short_description,
            'symptoms' => $this->flatStringList($service->symptoms),
            'scope_items' => $this->flatStringList($service->scope_items),
            'process_steps' => $this->flatStringList($service->process_steps),
            'cost_factors' => $this->flatStringList($service->cost_factors),
            'price_range' => is_array($service->price_range) ? $service->price_range : [],
            'comparison' => $this->normalizeComparison(is_array($service->comparison) ? $service->comparison : []),
            'warranty_applicable' => (bool) $service->warranty_applicable,
            'description' => (string) $service->description,
        ];
    }

    /**
     * Flatten the comparison block's simple() point repeaters (option_a/b → points) so a nested/legacy
     * shape can't break the render when the owner has the comparison section on. Left as-is otherwise.
     *
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function normalizeComparison(array $comparison): array
    {
        foreach (['option_a', 'option_b'] as $opt) {
            $side = $comparison[$opt] ?? null;
            if (is_array($side)) {
                $side['points'] = $this->flatStringList($side['points'] ?? []);
                $comparison[$opt] = $side;
            }
        }

        return $comparison;
    }

    /**
     * Coerce any stored shape into a flat list of non-empty strings — what a simple() Repeater expects.
     * Tolerates null, a bare string, a flat list, and the nested `[{item: …}]` / `[{value: …}]` shapes.
     *
     * @return list<string>
     */
    private function flatStringList(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : [trim($value)];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $s = trim((string) $item);
            } elseif (is_array($item)) {
                $candidate = $item['item'] ?? $item['value'] ?? null;
                $s = (is_string($candidate) || is_numeric($candidate)) ? trim((string) $candidate) : '';
            } else {
                $s = '';
            }
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** @return array{state: 'complete'|'attention'|'empty', label: string} */
    public function readiness(): array
    {
        $services = $this->getServicesProperty();
        if ($services->isEmpty()) {
            return ['state' => 'empty', 'label' => 'No services yet'];
        }

        $enriched = $services->filter(fn (Service $s) => collect($s->symptoms ?? [])->isNotEmpty()
            || collect($s->scope_items ?? [])->isNotEmpty()
            || trim((string) $s->short_description) !== '')->count();

        if ($enriched === 0) {
            return ['state' => 'attention', 'label' => 'Stated list only — nothing enriched yet'];
        }

        return $enriched === $services->count()
            ? ['state' => 'complete', 'label' => 'Complete']
            : ['state' => 'attention', 'label' => ($services->count() - $enriched).' service(s) not enriched'];
    }

    private function owned(string $serviceId): ?Service
    {
        return $this->siteId === null || $serviceId === '' ? null : Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($serviceId)
            ->first();
    }
}
