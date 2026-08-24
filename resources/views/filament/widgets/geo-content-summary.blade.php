<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">AI-search content — shipped body of work</x-slot>
        <x-slot name="description">GEO-lane posts across the pipeline. Published pages are grouped by silo.</x-slot>

        <div class="gcs">
            <style>
                .gcs { --gcs-line:#e2e7ee; --gcs-muted:#5a6675; --gcs-faint:#8a95a3; }
                .dark .gcs { --gcs-line:#232c37; --gcs-muted:#9aa7b5; --gcs-faint:#6b7887; }
                .gcs .gcs-stats { display:flex; gap:26px; flex-wrap:wrap; margin-bottom:16px; }
                .gcs .gcs-stat b { display:block; font-size:22px; font-weight:800; font-variant-numeric:tabular-nums; }
                .gcs .gcs-stat span { font-size:11.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--gcs-faint); }
                .gcs table.gcs-silos { width:100%; border-collapse:collapse; font-size:13px; }
                .gcs .gcs-silos th, .gcs .gcs-silos td { border-bottom:1px solid var(--gcs-line); padding:7px 10px; text-align:left; }
                .gcs .gcs-silos th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--gcs-faint); font-weight:700; }
                .gcs .gcs-silos td.gcs-count { text-align:right; font-weight:700; font-variant-numeric:tabular-nums; }
                .gcs .gcs-empty { font-size:13px; color:var(--gcs-muted); padding:6px 0; }
            </style>

            <div class="gcs-stats">
                <div class="gcs-stat"><b>{{ $counts['candidates'] }}</b><span>Candidates</span></div>
                <div class="gcs-stat"><b>{{ $counts['in_review'] }}</b><span>In review</span></div>
                <div class="gcs-stat"><b>{{ $counts['published'] }}</b><span>Published</span></div>
            </div>

            @if (count($silos) > 0)
                <table class="gcs-silos">
                    <thead>
                        <tr>
                            <th>Silo</th>
                            <th>Tenant</th>
                            <th style="text-align:right;">Published</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($silos as $row)
                            <tr>
                                <td>{{ $row['silo'] }}</td>
                                <td>{{ $row['tenant'] ?? '—' }}</td>
                                <td class="gcs-count">{{ $row['published'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="gcs-empty">No GEO-lane content has been published yet — approve &amp; publish from the queue below to start the tally.</div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
