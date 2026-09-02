<x-filament-panels::page>
    <style>
        .ri-wrap { display:flex; flex-direction:column; gap:16px; max-width:820px; }
        .ri-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:16px; }
        .ri-field label { display:block; font-size:12px; color:#94a3b8; margin-bottom:4px; }
        .ri-field input[type=text], .ri-field select { border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:9px 11px; font-size:13px; background:transparent; color:inherit; min-width:220px; }
        .ri-map { display:grid; grid-template-columns:130px 1fr; gap:8px 12px; align-items:center; margin-top:8px; }
        .ri-map label { font-size:13px; }
        table.ri-prev { width:100%; border-collapse:collapse; font-size:12px; margin-top:10px; }
        table.ri-prev th, table.ri-prev td { border:1px solid rgba(148,163,184,.25); padding:5px 8px; text-align:left; }
        .ri-imports { font-size:13px; }
        .ri-imports li { margin:3px 0; color:#647380; }
    </style>

    <div class="ri-wrap">
        <div class="ri-card">
            <div class="ri-field" style="margin-bottom:10px">
                <label>Upload a CSV or XLSX</label>
                <input type="file" wire:model="upload" accept=".csv,.xlsx">
            </div>
            <div class="ri-field" style="margin-bottom:10px">
                <label>…or paste a Google Sheet URL</label>
                <input type="text" wire:model="sheetUrl" placeholder="https://docs.google.com/spreadsheets/d/…">
            </div>
            <div class="ri-field" style="margin-bottom:10px">
                <label>Import source label (e.g. google, facebook, angi)</label>
                <input type="text" wire:model="importSource" placeholder="google">
            </div>
            <x-filament::button wire:click="detect" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass">
                Detect columns
            </x-filament::button>
        </div>

        @if ($columns !== [])
            <div class="ri-card">
                <strong style="font-size:14px">Map columns</strong>
                <div class="ri-map">
                    @foreach (\App\Reviews\Import\ReviewImporter::FIELDS as $field)
                        <label>{{ str_replace('_', ' ', $field) }}{{ in_array($field, ['rating','body','reviewed_at']) ? ' *' : '' }}</label>
                        <select wire:model="mapping.{{ $field }}" class="ri-field">
                            <option value="">—</option>
                            @foreach ($columns as $column)
                                <option value="{{ $column }}">{{ $column }}</option>
                            @endforeach
                        </select>
                    @endforeach
                </div>

                @if ($preview !== [])
                    <table class="ri-prev">
                        <thead><tr>@foreach ($columns as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($preview as $row)
                                <tr>@foreach ($columns as $c)<td>{{ \Illuminate\Support\Str::limit($row[$c] ?? '', 40) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <div style="margin-top:14px">
                    <x-filament::button wire:click="import" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray" color="success">
                        Import (queued)
                    </x-filament::button>
                </div>
            </div>
        @endif

        @php $recent = $this->recentImports; @endphp
        @if ($recent !== [])
            <div class="ri-card ri-imports">
                <strong style="font-size:14px">Recent imports</strong>
                <ul>
                    @foreach ($recent as $i)
                        <li>{{ $i['filename'] }} — <strong>{{ $i['status'] }}</strong> · {{ $i['imported'] }} imported, {{ $i['skipped'] }} skipped · {{ $i['created'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
