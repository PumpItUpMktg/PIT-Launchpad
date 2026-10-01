<x-filament-panels::page>
    @include('filament.activity._report', [
        'report' => $this->report,
        'progress' => $this->tab === 'progress' ? $this->progress : [],
        'tab' => $this->tab,
        'periodOptions' => $this->periodOptions,
        'clientView' => false,
    ])
</x-filament-panels::page>
