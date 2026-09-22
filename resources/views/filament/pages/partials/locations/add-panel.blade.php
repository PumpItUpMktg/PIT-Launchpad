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
        {{-- Two boxes, and the address is the one that matters: it is the only thing the geocoder reads.
             On a fresh tenant the full street address went into the name box, the address stayed empty,
             and the location could never be located. Label both so that cannot happen by accident. --}}
        <input type="text" wire:model="addName" placeholder="Name — how you refer to it (e.g. Perkasie shop)" class="lp-input" />
        <input type="text" wire:model="addAddress" placeholder="Street address, town, state, ZIP — required, this is what gets located" class="lp-input" />
    @endif

    <p class="lp-muted" style="margin:0">We’ll locate it and pre-tick its home county — adjust the counties you serve on the tab.</p>

    <div class="lp-row">
        @if ($addSource !== 'places' || ! $this->placesEnabled)
            <button type="button" wire:click="addManual" class="lp-btn">Add location</button>
        @endif
        <button type="button" wire:click="cancelAdd" class="lp-btn ghost">Cancel</button>
    </div>
</div>
