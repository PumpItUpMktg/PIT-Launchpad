{{-- Location tabs + the Add-location tab. Expects $locations, $colors, $vm, $activeLoc,
     $locating from the including blade (plus $adding from the component). --}}
<div class="lp-tabs" @if ($locating) wire:poll.3s @endif>
    @foreach ($locations as $location)
        @php
            $isActive = $activeLoc && $location->id === $activeLoc->id && ! $adding;
            $color = $colors[$location->id] ?? '#2563eb';
            $p = $vm['panels'][$location->id] ?? ['town_count' => 0, 'selected_count' => 0];
        @endphp
        <button type="button" wire:click="$set('activeTab', '{{ $location->id }}')" class="lp-tab {{ $isActive ? 'active' : '' }}">
            <span class="lp-dot" style="background: {{ $color }}"></span>
            <span class="lp-tab-name">{{ $location->name }}</span>
            <span class="lp-tab-count">{{ $p['town_count'] }}</span>
            @if ($p['selected_count'] > 0)
                <span class="lp-tab-badge">{{ $p['selected_count'] }}</span>
            @endif
        </button>
    @endforeach
    <button type="button" wire:click="startAdd" class="lp-tab add {{ $adding ? 'on' : '' }}">＋ Add location</button>
</div>
