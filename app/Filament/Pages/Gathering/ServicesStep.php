<?php

namespace App\Filament\Pages\Gathering;

use App\Filament\Resources\ServiceResource;
use App\Guided\ServiceSuggester;
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

                return $service === null ? [] : $service->only(self::ENRICHMENT_FIELDS);
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
