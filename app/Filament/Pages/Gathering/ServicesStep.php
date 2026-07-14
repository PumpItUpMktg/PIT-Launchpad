<?php

namespace App\Filament\Pages\Gathering;

use App\Filament\Resources\ServiceResource;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * New Setup · Step 4 — Services review surface. The stated list with add/remove; per-service
 * enrichment edits open THE service form from {@see ServiceResource::enrichmentComponents()} —
 * the exact contract from the service-pages relay, never a second copy. Interview-seeded fields
 * carry the "from interview" chip on the card; saving the modal confirms them.
 *
 * @property-read Collection<int, Service> $services
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
