{{-- The Pages boards' per-card controls, passed into <x-lp.content-card>'s `actions` slot. These are the
     affordances Live doesn't have — they survive as slotted options on the shared component, not dropped.
     Rendered inside the board Livewire component, so $this is the board (repush / regenerate / takeDown /
     assignLocation / toggleCityPriority / toggleNavFeatured / setNavOrder + navState all live there).
     Expects $card; optional $locationOptions (orphan town → assign picker), $navControl (city + header-menu
     controls, only the Operate pages boards pass it). --}}
<div style="display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
    <div style="display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
        <a class="lv-btn" href="{{ \App\Filament\Pages\ProofEditor::getUrl(['content' => $card['id']]) }}" wire:navigate>Review</a>
        <button type="button" class="lv-btn primary" wire:click="repush('{{ $card['id'] }}')">Repush</button>
        <button type="button" class="lv-btn" wire:click="regenerate('{{ $card['id'] }}')">Regenerate</button>
        <button type="button" class="lv-btn danger" wire:click="takeDown('{{ $card['id'] }}')" wire:confirm="Remove this page from WordPress? It stays in your plan and can be republished on the same URL.">Take down</button>
        @if (($locationOptions ?? []) !== [])
            <select class="lv-select" wire:change="assignLocation('{{ $card['id'] }}', $event.target.value)" title="assign this town to a location">
                <option value="">assign location…</option>
                @foreach ($locationOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Priority-city toggle: only city pages carry a market, so it shows for those alone. Promoting a city
         assigns its "{service} {city}" tracking keywords (§5); the highlight reflects current tier. --}}
    @if (($navControl ?? false) && ($card['market_priority'] ?? null) !== null)
        <button type="button" wire:click="toggleCityPriority('{{ $card['id'] }}')" wire:loading.attr="disabled"
                title="{{ $card['market_priority'] ? 'A priority city — tracked in DataForSEO. Click to set back to coverage.' : 'Mark this city priority to track its rankings (uses DataForSEO credits on the next pull).' }}"
                style="font-size:11px; font-weight:600; padding:3px 10px; border-radius:99px; cursor:pointer;
                       {{ $card['market_priority']
                           ? 'color:#92400e; background:rgba(217,119,6,.15); border:1px solid rgba(217,119,6,.4);'
                           : 'color:#64748b; background:transparent; border:1px solid rgba(148,163,184,.4);' }}">
            {{ $card['market_priority'] ? '★ Priority city' : '☆ Mark priority' }}
        </button>
    @endif

    {{-- Header-menu curation: check a page to pin it into the site header's main menu; the order number sorts
         it. Changes go live on the next Sync header & footer push. --}}
    @if ($navControl ?? false)
        @php $nav = $this->navState[$card['id']] ?? ['featured' => false, 'order' => null]; @endphp
        <div class="lv-navctl">
            <label title="Show this page in the site header's main menu">
                <input type="checkbox" wire:click="toggleNavFeatured('{{ $card['id'] }}')" @checked($nav['featured'])>
                In header menu
            </label>
            <label class="lv-navorder-lbl" title="Menu order (lower shows first; blank = automatic). Check the box first.">
                order
                <input type="number" min="1" class="lv-navorder" placeholder="auto" value="{{ $nav['order'] }}"
                       wire:change="setNavOrder('{{ $card['id'] }}', $event.target.value)" @disabled(! $nav['featured'])>
            </label>
        </div>
    @endif
</div>
