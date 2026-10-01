{{-- The client's activity page: the same report with the operator-only entries filtered out. --}}
<x-filament-panels::page>
    @include('filament.activity._report', [
        'report' => $this->report,
        'progress' => $this->tab === 'progress' ? $this->progress : [],
        'tab' => $this->tab,
        'periodOptions' => $this->periodOptions,
        'siteOptions' => $this->siteOptions,
        'clientView' => true,
    ])
</x-filament-panels::page>
