<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'Operator-only: the WordPress connection, keys, and news feeds. State summarized here; management flows open the existing surfaces.'])

        @php $summary = $this->summary; @endphp

        <div class="g-grid2">
            <div class="g-card">
                <h3>WordPress & credentials</h3>
                <div class="g-list">
                    <div class="g-item">
                        <span>WordPress connection</span>
                        <span class="g-seed {{ $summary['wp']['present'] && ! $summary['wp']['compromised'] ? 'confirmed' : '' }}">
                            {{ $summary['wp']['present'] ? ($summary['wp']['compromised'] ? 'flagged compromised' : 'connected') : 'not connected' }}
                        </span>
                    </div>
                    <div class="g-item"><span>Credentials on file</span><span class="g-muted">{{ $summary['wp']['provider_count'] }}</span></div>
                </div>
                <a class="g-btn" href="{{ $this->connectionsUrl() }}" wire:navigate>Open Connections →</a>
            </div>

            <div class="g-card">
                <h3>News feeds</h3>
                <div class="g-list">
                    <div class="g-item"><span>Feeds</span><span class="g-muted">{{ $summary['feeds']['total'] }} total · {{ $summary['feeds']['enabled'] }} enabled</span></div>
                </div>
                <a class="g-btn" href="{{ $this->feedsUrl() }}" wire:navigate>Open Feeds →</a>
            </div>
        </div>
        @include('filament.gathering._next')
    </div>
</x-filament-panels::page>
