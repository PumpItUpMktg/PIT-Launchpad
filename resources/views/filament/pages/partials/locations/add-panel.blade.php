{{-- The Add-location flow (Google listing or manual name + address). Uses component state only. --}}
<div class="lp-card lp-add-stack">
    @if ($this->placesEnabled)
        <div class="lp-seg">
            <button type="button" wire:click="$set('addSource', 'places')" class="{{ $addSource === 'places' ? 'on' : '' }}">From Google</button>
            <button type="button" wire:click="$set('addSource', 'manual')" class="{{ $addSource === 'manual' ? 'on' : '' }}">Enter manually</button>
        </div>
    @endif

    @if ($addSource === 'places' && $this->placesEnabled)
        <input type="text" wire:model="addQuery" wire:keydown.enter="searchPlaces" placeholder="business name or address" class="lp-input" />
        <div class="lp-row"><button type="button" wire:click="searchPlaces" class="lp-btn ghost">Search</button></div>
        @foreach ($placeResults as $r)
            <button type="button" wire:click="addFromPlace('{{ $r['place_id'] }}')" class="lp-result">
                <strong>{{ $r['name'] }}</strong><br><span class="lp-muted">{{ $r['address'] }}</span>
            </button>
        @endforeach
    @else
        <input type="text" wire:model="addName" placeholder="Location name (e.g. Montclair)" class="lp-input" />
        <input type="text" wire:model="addAddress" placeholder="Where you are (address)" class="lp-input" />
    @endif

    <p class="lp-muted" style="margin:0">We’ll locate it and pre-tick its home county — adjust the counties you serve on the tab.</p>

    <div class="lp-row">
        @if ($addSource !== 'places' || ! $this->placesEnabled)
            <button type="button" wire:click="addManual" class="lp-btn">Add location</button>
        @endif
        <button type="button" wire:click="cancelAdd" class="lp-btn ghost">Cancel</button>
    </div>
</div>
