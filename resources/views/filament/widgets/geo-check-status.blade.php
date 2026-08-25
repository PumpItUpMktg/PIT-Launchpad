<x-filament-widgets::widget>
    @if (count($running) > 0)
        <x-filament::section>
            <div class="gcs-run">
                <style>
                    .gcs-run { --gcs-line:#e2e7ee; --gcs-accent:#2563eb; }
                    .dark .gcs-run { --gcs-line:#232c37; --gcs-accent:#60a5fa; }
                    .gcs-run .gcs-row { display:flex; align-items:center; gap:12px; padding:6px 0; }
                    .gcs-run .gcs-row + .gcs-row { border-top:1px solid var(--gcs-line); }
                    .gcs-run .gcs-spin { width:15px; height:15px; border:2px solid var(--gcs-line); border-top-color:var(--gcs-accent); border-radius:50%; animation:gcs-spin 0.8s linear infinite; flex:none; }
                    @keyframes gcs-spin { to { transform:rotate(360deg); } }
                    .gcs-run .gcs-label { font-size:13.5px; font-weight:600; }
                    .gcs-run .gcs-count { font-size:12.5px; color:var(--gcs-accent); font-weight:700; font-variant-numeric:tabular-nums; }
                    .gcs-run .gcs-bar { flex:1; height:6px; border-radius:3px; background:var(--gcs-line); overflow:hidden; min-width:80px; max-width:220px; }
                    .gcs-run .gcs-bar i { display:block; height:100%; background:var(--gcs-accent); transition:width .4s ease; }
                </style>

                @foreach ($running as $r)
                    @php($pct = $r['total'] > 0 ? (int) round($r['measured'] / $r['total'] * 100) : 0)
                    <div class="gcs-row">
                        <span class="gcs-spin"></span>
                        <span class="gcs-label">Checking AI visibility — {{ $r['tenant'] }}</span>
                        <span class="gcs-bar"><i style="width: {{ $pct }}%"></i></span>
                        <span class="gcs-count">{{ $r['measured'] }}/{{ $r['total'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
