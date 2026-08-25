<x-filament-widgets::widget>
    @if ($counts !== null)
        <x-filament::section>
            <x-slot name="heading">GEO check activity</x-slot>
            <x-slot name="description">What the engine did on the latest run — newest first.</x-slot>

            <div class="gca">
                <style>
                    .gca { --gca-line:#e2e7ee; --gca-muted:#5a6675; --gca-faint:#8a95a3; }
                    .dark .gca { --gca-line:#232c37; --gca-muted:#9aa7b5; --gca-faint:#6b7887; }
                    .gca .gca-counts { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:14px; font-size:12.5px; color:var(--gca-muted); }
                    .gca .gca-counts b { color:inherit; font-weight:800; font-variant-numeric:tabular-nums; }
                    .gca .gca-list { max-height:280px; overflow-y:auto; }
                    .gca .gca-row { display:flex; align-items:baseline; gap:10px; padding:6px 0; border-bottom:1px solid var(--gca-line); font-size:13px; }
                    .gca .gca-dot { width:8px; height:8px; border-radius:50%; flex:none; align-self:center; }
                    .gca .gca-where { font-weight:600; min-width:0; }
                    .gca .gca-eng { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--gca-faint); }
                    .gca .gca-act { margin-left:auto; font-size:11.5px; color:var(--gca-muted); white-space:nowrap; }
                    .gca .gca-cited { color:#15803d; font-weight:700; }
                    .gca .gca-absent { color:#c0392b; font-weight:700; }
                    .gca .gca-comp { color:#c0392b; font-size:11.5px; }
                    .gca .gca-empty { font-size:13px; color:var(--gca-muted); padding:6px 0; }
                </style>

                <div class="gca-counts">
                    <span><b>{{ $counts['measured'] }}</b> measured</span>
                    <span><b>{{ $counts['skipped_fresh'] }}</b> fresh</span>
                    <span><b>{{ $counts['deferred'] }}</b> deferred</span>
                    <span><b>{{ $counts['error'] }}</b> errors</span>
                </div>

                @if (count($events) > 0)
                    <div class="gca-list">
                        @foreach ($events as $e)
                            <div class="gca-row">
                                <span class="gca-dot" style="background: {{ $e['color'] }}"></span>
                                <span class="gca-where">{{ $e['town'] ?? 'Service-wide' }}</span>
                                <span class="gca-eng">{{ $e['engine'] }}</span>
                                @if ($e['is_measured'])
                                    @if ($e['cited'])
                                        <span class="gca-cited">cited</span>
                                    @else
                                        <span class="gca-absent">absent</span>@if (! empty($e['competitors']))<span class="gca-comp">— {{ implode(', ', array_slice($e['competitors'], 0, 3)) }}</span>@endif
                                    @endif
                                @endif
                                <span class="gca-act">{{ $e['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="gca-empty">No steps recorded yet.</div>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
