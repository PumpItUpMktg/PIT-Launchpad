<?php

namespace App\Filament\Pages\Guided;

use App\Enums\SetupStep;
use App\Guided\GuidedPage;
use App\Guided\ServiceSuggester;
use App\Guided\StepGate;
use App\Interview\SiloSeed;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use Filament\Notifications\Notification;

/**
 * Step 1 · Business & services. Brand + trade + stated services, with the connecting-services
 * suggester ({@see ServiceSuggester}) — confirmed suggestions become stated services (tagged
 * `suggested-confirmed`). Continue persists a {@see SiloSeed} onto the site's blueprint (what
 * Step 3's expansion grounds against) and advances.
 */
class Business extends GuidedPage
{
    protected static ?string $slug = 'setup';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Business & services';

    protected string $view = 'filament.guided.business';

    public string $businessName = '';

    public string $trade = '';

    /** @var list<string> */
    public array $services = [];

    public string $newService = '';

    /** @var list<array{name: string, why: string, on: bool}> */
    public array $suggestions = [];

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
        $blueprint = $this->blueprint($site);
        $seed = ($blueprint !== null && is_array($blueprint->seed)) ? $blueprint->seed : [];
        $this->trade = (string) ($seed['trade'] ?? '');
        $this->services = $this->stringList($seed['anchor_services'] ?? []);

        if ($this->trade !== '') {
            $this->suggest();
        }
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

        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->firstOrCreate(['site_id' => $site->id]);
        $seed = SiloSeed::fromArray([...($blueprint->seed ?? []), 'trade' => $this->trade, 'anchor_services' => $anchor]);
        $blueprint->update([
            'trade' => $this->trade,
            'seed' => [...$seed->toArray(), 'suggested_confirmed' => $confirmed],
        ]);

        $gate = app(StepGate::class);
        $gate->complete($gate->state($site), SetupStep::Business);

        Notification::make()->title('Services saved.')->success()->send();
        $this->redirect(SetupStep::Territory->pageClass()::getUrl());
    }

    private function blueprint(Site $site): ?SiloBlueprint
    {
        return SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
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
