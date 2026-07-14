<x-filament-panels::page>
    <div class="g-wrap">
        @include('filament.gathering._top', ['subtitle' => 'Who the business is: GBP import, identity, and the trust facts your pages lean on. The interview can seed the trust facts — saving here confirms them.'])

        @php $site = $this->getSite(); $prov = $site !== null ? $this->provenanceFor($site) : []; @endphp

        <div class="g-card">
            <h3>Identity</h3>
            <div class="g-grid2">
                <div class="g-field"><label>Business name</label><input class="g-input" type="text" wire:model="brandName"></div>
                <div class="g-field"><label>Phone</label><input class="g-input" type="text" wire:model="phone"></div>
                <div class="g-field"><label>Website URL</label><input class="g-input" type="text" wire:model="domainUrl"></div>
            </div>
        </div>

        <div class="g-card">
            <h3>Trust facts</h3>
            <p class="g-hint">License, insurance, years, warranty, guarantees — manual entry here; the interview can fill them for you.</p>
            <div class="g-grid2">
                <div class="g-field"><label>License # @isset($prov['license_number'])<span class="g-seed {{ $prov['license_number']->value }}">{{ $prov['license_number']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset</label><input class="g-input" type="text" wire:model="licenseNumber"></div>
                <div class="g-field"><label>Insured @isset($prov['insured'])<span class="g-seed {{ $prov['insured']->value }}">{{ $prov['insured']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset</label>
                    <select class="g-input" wire:model="insured"><option value="unknown">Unknown</option><option value="yes">Yes</option><option value="no">No</option></select>
                </div>
                <div class="g-field"><label>Years in business @isset($prov['years_in_business'])<span class="g-seed {{ $prov['years_in_business']->value }}">{{ $prov['years_in_business']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset</label><input class="g-input" type="number" min="0" wire:model="yearsInBusiness"></div>
            </div>
            <div class="g-field"><label>Warranty program @isset($prov['warranty_program'])<span class="g-seed {{ $prov['warranty_program']->value }}">{{ $prov['warranty_program']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset</label><textarea class="g-textarea" rows="2" wire:model="warrantyProgram"></textarea></div>
            <div class="g-field"><label>Guarantees @isset($prov['guarantees'])<span class="g-seed {{ $prov['guarantees']->value }}">{{ $prov['guarantees']->value === 'seeded' ? 'from interview' : 'confirmed' }}</span>@endisset</label><textarea class="g-textarea" rows="2" wire:model="guarantees"></textarea></div>
            <button class="g-btn primary" wire:click="save">Save business</button>
        </div>

        <div class="g-card">
            <h3>Import locations from Google</h3>
            <p class="g-hint">One GBP URL or business name per line. Each resolved line creates a location skeleton — failures stay editable below and never block the rest.</p>
            <textarea class="g-textarea" rows="4" wire:model="bulkInput" placeholder="Sump Pump Geeks Trooper PA&#10;https://maps.google.com/…"></textarea>
            <div class="g-row">
                <button class="g-btn" wire:click="resolveBulk">Resolve</button>
                @if (collect($bulkResults)->where('status', 'resolved')->isNotEmpty())
                    <button class="g-btn primary" wire:click="importResolved">Create location skeletons</button>
                @endif
            </div>

            @if ($bulkResults !== [])
                <div class="g-list">
                    @foreach ($bulkResults as $i => $row)
                        <div class="g-item" wire:key="bulk-{{ $i }}">
                            @if ($row['status'] === 'failed')
                                <span class="g-seed" style="background:rgba(220,38,38,.12);color:#dc2626">failed</span>
                                <input class="g-input" style="max-width:340px" type="text" wire:model="bulkResults.{{ $i }}.query">
                                <span class="g-muted">{{ $row['message'] }}</span>
                                <button class="g-btn" style="margin-left:auto" wire:click="retryLine({{ $i }})">Retry</button>
                            @else
                                <span class="g-seed {{ $row['status'] === 'imported' ? 'confirmed' : '' }}">{{ $row['status'] }}</span>
                                <strong>{{ $row['name'] }}</strong>
                                <span class="g-muted">{{ $row['address'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
